<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Tests\Integration\Transaction;

use JardisSupport\Contract\Kernel\ResponseStatus;
use JardisSupport\Workflow\Data\NodeResult;
use JardisSupport\Workflow\Data\ProcessContext;
use JardisSupport\Workflow\Data\ProcessDefinition;
use JardisSupport\Workflow\Data\Route;
use JardisSupport\Workflow\Tests\Support\LoadDefinition;
use JardisSupport\Workflow\Tests\Support\TransactionTestCase;

/**
 * The two caller patterns of the proof set on SQLite: a batch that runs an inner process (PA-2,
 * also across a bounded context) and a process that opted out of the transaction (PA-3).
 */
class ProcessPatternsTest extends TransactionTestCase
{
    private const BATCH = 'BatchOrderFulfilment';
    private const OPT_OUT = 'RegisterAndReviseServiceOptOut';
    private const BATCH_STEP = 'Inner\\Step';

    /**
     * @param array<string, string> $terminals node names that get a terminal onSuccess edge
     */
    private function load(string $fixture, array $terminals = []): ProcessDefinition
    {
        $json = file_get_contents(dirname(__DIR__, 2) . '/Fixture/' . $fixture . '.json');
        $this->assertNotFalse($json);
        /** @var array{nodes: list<array{id: string, name: string}>, edges: list<array<string, mixed>>} $document */
        $document = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        foreach ($document['nodes'] as $node) {
            if (in_array($node['name'], $terminals, true)) {
                $document['edges'][] = ['from' => $node['id'], 'on' => 'onSuccess', 'terminal' => true];
            }
        }

        return (new LoadDefinition())(json_encode($document, JSON_THROW_ON_ERROR));
    }

    private function node(string $process, string $name): string
    {
        return LoadDefinition::classOf($process, $name);
    }

    private function batchStep(): ProcessDefinition
    {
        return ProcessDefinition::for('In', 'OrderInvoiceBatch')->node(self::BATCH_STEP)->terminal()->build();
    }

    /**
     * @return array<string, callable(ProcessContext): NodeResult>
     */
    private function batchNodes(callable $runBatch): array
    {
        return [
            $this->node(self::BATCH, 'BatchOrderFulfilmentEntry') => $this->writes('entry'),
            $this->node(self::BATCH, 'RunBatch') => $runBatch,
            $this->node(self::BATCH, 'BatchOrderFulfilmentFinalize') => $this->writes('final'),
        ];
    }

    public function testBatchRunsItsInnerProcessInASavepointAndKeepsEverythingOnSuccess(): void
    {
        $runner = $this->runnerOn();
        $definition = $this->load(self::BATCH, ['BatchOrderFulfilmentFinalize']);
        $inner = $this->factory([self::BATCH_STEP => $this->writes('batch')]);

        $outcome = $runner->run($definition, new \stdClass(), null, $this->factory(
            $this->batchNodes($this->subProcess($runner, $this->batchStep(), $inner, Route::OnFalse))
        ));

        $this->assertSame(ResponseStatus::Success, $outcome->status());
        $this->assertSame(['entry', 'batch', 'final'], $this->ledger->notes());
        $this->assertSame(
            ['beginTransaction', 'savepoint:sp_1', 'release:sp_1', 'commit'],
            $this->connection->calls()
        );
    }

    public function testA4xxInsideTheBatchRollsBackOnlyTheInnerRun(): void
    {
        $runner = $this->runnerOn();
        $definition = $this->load(self::BATCH, ['BatchOrderFulfilmentFinalize']);
        $inner = $this->factory([
            self::BATCH_STEP => $this->writesThenRejects('batch', ResponseStatus::RuleViolation, 'batch.rule'),
        ]);

        $outcome = $runner->run($definition, new \stdClass(), null, $this->factory(
            $this->batchNodes($this->subProcess($runner, $this->batchStep(), $inner, Route::OnFalse))
        ));

        $this->assertSame(ResponseStatus::Success, $outcome->status(), 'the batch decided: onFail leads to the finalize node');
        $this->assertSame(['entry', 'final'], $this->ledger->notes(), 'the inner row is gone, the outer rows stay');
        $this->assertSame(
            ['beginTransaction', 'savepoint:sp_1', 'rollbackTo:sp_1', 'commit'],
            $this->connection->calls()
        );
    }

    public function testProcessOfAnotherContextCalledInsideAnOpenTransactionWorksInASavepoint(): void
    {
        $runner = $this->runnerOn();
        $foreign = ProcessDefinition::for('In', 'ForeignProcess')->node(self::BATCH_STEP)->terminal()->build();
        $caller = ProcessDefinition::for('In', 'Caller')
            ->node('Node\\Call')->onTrue('Node\\After')->onFalse('Node\\After')
            ->node('Node\\After')->terminal()
            ->build();
        $call = function (ProcessContext $c) use ($runner, $foreign): NodeResult {
            // the other context's public door: a run of its own, no parent
            $outcome = $runner->run($foreign, new \stdClass(), null, $this->factory([
                self::BATCH_STEP => $this->writesThenRejects('foreign', ResponseStatus::NotFound, 'foreign.gone'),
            ]));

            return NodeResult::route($outcome->status()->value >= 400 ? Route::OnFalse : Route::OnTrue);
        };

        $outcome = $runner->run($caller, new \stdClass(), null, $this->factory([
            'Node\\Call' => $call,
            'Node\\After' => $this->writes('after'),
        ]));

        $this->assertSame(ResponseStatus::Success, $outcome->status());
        $this->assertSame(['after'], $this->ledger->notes());
        $this->assertSame(1, $this->connection->count('beginTransaction'), 'the foreign run began none');
        $this->assertSame(
            ['beginTransaction', 'savepoint:sp_0', 'rollbackTo:sp_0', 'commit'],
            $this->connection->calls()
        );
    }

    public function testOptOutServiceRunsWithoutTransactionAndCarriesItsEvents(): void
    {
        $registered = new \stdClass();
        $revised = new \stdClass();
        $definition = $this->load(self::OPT_OUT);

        $outcome = $this->runnerOn()->run($definition, new \stdClass(), null, $this->factory([
            $this->node(self::OPT_OUT, 'RegisterNewService') => function (ProcessContext $c) use ($registered): NodeResult {
                $this->ledger->add('registered');

                return NodeResult::ok(['service' => 'S-1'])->withEvents($registered);
            },
            $this->node(self::OPT_OUT, 'ReviseExistingService') => function (ProcessContext $c) use ($revised): NodeResult {
                $this->ledger->add('revised');

                return NodeResult::ok(['service' => 'S-1', 'rev' => 2])->withEvents($revised);
            },
        ]));

        $this->assertSame(ResponseStatus::Success, $outcome->status());
        $this->assertSame([], $this->connection->calls(), '0x beginTransaction');
        $this->assertSame(['registered', 'revised'], $this->ledger->notes());
        $this->assertSame([$registered, $revised], $outcome->events());
    }

    public function testOptOutServiceKeepsItsFirstWriteWhenTheSecondNodeRejects(): void
    {
        $definition = $this->load(self::OPT_OUT);

        $outcome = $this->runnerOn()->run($definition, new \stdClass(), null, $this->factory([
            $this->node(self::OPT_OUT, 'RegisterNewService') => $this->writes('registered'),
            $this->node(self::OPT_OUT, 'ReviseExistingService')
                => $this->writesThenRejects('revised', ResponseStatus::NotFound, 'registry.revise_target_missing'),
        ]));

        $this->assertSame(ResponseStatus::NotFound, $outcome->status());
        $this->assertSame([], $this->connection->calls());
        $this->assertSame(['registered', 'revised'], $this->ledger->notes(), 'no bracket, no rollback');
        $this->assertSame([], $outcome->events());
    }
}
