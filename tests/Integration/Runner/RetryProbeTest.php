<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Tests\Integration\Runner;

use JardisSupport\Contract\Kernel\ResponseStatus;
use JardisSupport\Workflow\Data\ProcessDefinition;
use JardisSupport\Workflow\Data\Route;
use JardisSupport\Workflow\Exception\LoopLimitExceededException;
use JardisSupport\Workflow\Tests\Support\LoadDefinition;
use JardisSupport\Workflow\Tests\Support\Process\RetryProbeUntilHealthy\Action\ReadProbeHealth;
use JardisSupport\Workflow\Tests\Support\Process\RetryProbeUntilHealthy\Command\EvaluateProbeHealth;
use JardisSupport\Workflow\Tests\Support\Process\RetryProbeUntilHealthy\Command\WaitBeforeRetry;
use JardisSupport\Workflow\Tests\Support\RunnerTestCase;

/**
 * The retry pattern (PA-1, A-K5): a cycle over two nodes whose only way out is a terminal onExit edge,
 * counted by iteration(). The three legacy process documents load into valid definitions.
 */
class RetryProbeTest extends RunnerTestCase
{
    private const EVALUATE = EvaluateProbeHealth::class;
    private const WAIT = WaitBeforeRetry::class;

    private function load(string $fixture): ProcessDefinition
    {
        $json = file_get_contents(dirname(__DIR__, 2) . '/Fixture/' . $fixture . '.json');
        $this->assertNotFalse($json);

        return (new LoadDefinition())($json);
    }

    /**
     * @return array<string, callable>
     */
    private function nodes(int $healthyFrom): array
    {
        return [
            self::EVALUATE => new EvaluateProbeHealth(new ReadProbeHealth($healthyFrom)),
            self::WAIT => new WaitBeforeRetry(),
        ];
    }

    public function testLoopCountsIterationsAndLeavesOverOnExit(): void
    {
        $built = [];

        $outcome = $this->runner()->run(
            $this->load('RetryProbeUntilHealthy'),
            new \stdClass(),
            null,
            $this->factory($this->nodes(2), $built)
        );

        $this->assertSame(
            [self::EVALUATE, self::WAIT, self::EVALUATE, self::WAIT, self::EVALUATE],
            $built,
            'three evaluations, a wait between each, then the exit'
        );
        $this->assertSame(ResponseStatus::Success, $outcome->status());
        $this->assertEquals((object) ['evaluation' => 2, 'healthy' => true], $outcome->response());
    }

    public function testExitIsTakenOnTheFirstEvaluationWhenAlreadyHealthy(): void
    {
        $built = [];

        $this->runner()->run($this->load('RetryProbeUntilHealthy'), new \stdClass(), null, $this->factory($this->nodes(0), $built));

        $this->assertSame([self::EVALUATE], $built);
    }

    public function testEndlessVariantEndsAtTheLoopLimit(): void
    {
        $built = [];

        try {
            $this->runner()->run(
                $this->load('RetryProbeUntilHealthy'),
                new \stdClass(),
                null,
                $this->factory($this->nodes(PHP_INT_MAX), $built)
            );
            $this->fail('the endless variant must end at the loop limit');
        } catch (LoopLimitExceededException $e) {
            $this->assertSame(ProcessDefinition::DEFAULT_LOOP_LIMIT, $e->getLimit());
        }

        $this->assertCount(ProcessDefinition::DEFAULT_LOOP_LIMIT, $built);
    }

    public function testRetryDefinitionMapsTheLegacyEdges(): void
    {
        $config = $this->load('RetryProbeUntilHealthy')->config();

        $this->assertSame(self::EVALUATE, $config->entry());
        $this->assertSame(
            [Route::OnTrue->value => self::WAIT, Route::OnExit->value => null],
            $config->transitions(self::EVALUATE)
        );
        $this->assertSame([Route::OnTrue->value => self::EVALUATE], $config->transitions(self::WAIT));
    }

    public function testBatchFulfilmentFixtureLoads(): void
    {
        $definition = $this->load('BatchOrderFulfilment');
        $config = $definition->config();
        $prefix = 'JardisSupport\\Workflow\\Tests\\Support\\Process\\BatchOrderFulfilment\\Command\\';

        $this->assertSame($prefix . 'BatchOrderFulfilmentEntry', $config->entry());
        $this->assertCount(3, $config->nodes());
        $this->assertSame(
            [Route::OnTrue->value => $prefix . 'BatchOrderFulfilmentFinalize', Route::OnFalse->value => $prefix . 'BatchOrderFulfilmentFinalize'],
            $config->transitions($prefix . 'RunBatch')
        );
        $this->assertTrue($definition->isTransactional());
        $this->assertSame([], $definition->getRejects($prefix . 'RunBatch'), 'a 500 response is no reject');
    }

    public function testOptOutFixtureLoadsWithRejectsAndWithoutTransaction(): void
    {
        $definition = $this->load('RegisterAndReviseServiceOptOut');
        $prefix = 'JardisSupport\\Workflow\\Tests\\Support\\Process\\RegisterAndReviseServiceOptOut\\Command\\';
        $register = $prefix . 'RegisterNewService';

        $this->assertFalse($definition->isTransactional());
        $this->assertSame($register, $definition->config()->entry());
        $this->assertSame(
            [Route::OnTrue->value => $prefix . 'ReviseExistingService', Route::OnFalse->value => null],
            $definition->config()->transitions($register)
        );
        $this->assertSame(
            [
                ['status' => ResponseStatus::ValidationError, 'messageKey' => 'registry.register_input_rejected'],
                ['status' => ResponseStatus::Conflict, 'messageKey' => 'registry.register_service_clash'],
            ],
            $definition->getRejects($register)
        );
        $this->assertSame(
            [['status' => ResponseStatus::NotFound, 'messageKey' => 'registry.revise_target_missing']],
            $definition->getRejects($prefix . 'ReviseExistingService')
        );
    }
}
