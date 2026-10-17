<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Tests\Integration\Transaction;

use JardisSupport\Contract\Kernel\ResponseStatus;
use JardisSupport\Workflow\Data\Failure\ValidationFailure;
use JardisSupport\Workflow\Data\NodeResult;
use JardisSupport\Workflow\Data\ProcessContext;
use JardisSupport\Workflow\Data\ProcessDefinition;
use JardisSupport\Workflow\Data\ProcessOutcome;
use JardisSupport\Workflow\Data\Route;
use JardisSupport\Workflow\Data\RunRequest;
use JardisSupport\Workflow\Handler\Transaction\Bracket;
use JardisSupport\Workflow\ProcessRunner;
use JardisSupport\Workflow\Tests\Support\Process\Order\OrderInput;
use JardisSupport\Workflow\Tests\Support\Process\Order\OrderValidation;
use JardisSupport\Workflow\Tests\Support\TransactionTestCase;

/**
 * The transaction bracket over a real SQLite connection: own transaction, savepoints by depth,
 * rollback on a reject or a throw, opt-out (A-S5, A-S7(1)-(5), A-K3, A-E4, A-E6, A-E7).
 */
class BracketTest extends TransactionTestCase
{
    private const A = 'Node\\A';
    private const B = 'Node\\B';
    private const C = 'Node\\C';
    private const INNER_A = 'Inner\\A';
    private const INNER_B = 'Inner\\B';
    private const DEEP = 'Deep\\A';

    private function chainOfThree(): ProcessDefinition
    {
        return ProcessDefinition::for('In', 'P')
            ->node(self::A)->onTrue(self::B)
            ->node(self::B)->onTrue(self::C)
            ->node(self::C)->terminal()
            ->build();
    }

    private function inner(): ProcessDefinition
    {
        return ProcessDefinition::for('In', 'Inner')
            ->node(self::INNER_A)->onTrue(self::INNER_B)
            ->node(self::INNER_B)->terminal()
            ->build();
    }

    public function testRunnerRunTakesTheSurroundingRunAsAnOptionalParent(): void
    {
        $parameters = (new \ReflectionMethod(ProcessRunner::class, 'run'))->getParameters();
        $parent = end($parameters);

        $this->assertNotFalse($parent);
        $this->assertSame('parent', $parent->getName());
        $this->assertSame(ProcessContext::class, (string) $parent->getType()?->getName());
        $this->assertTrue($parent->allowsNull());
        $this->assertTrue($parent->isOptional());
    }

    public function testTopLevelRunOwnsItsTransactionAndCommits(): void
    {
        $outcome = $this->runnerOn()->run(
            $this->chainOfThree(),
            new \stdClass(),
            null,
            $this->factory([
                self::A => $this->writes('a'),
                self::B => $this->writes('b'),
                self::C => $this->writes('c'),
            ])
        );

        $this->assertSame(ResponseStatus::Success, $outcome->status());
        $this->assertSame(['beginTransaction', 'commit'], $this->connection->calls());
        $this->assertSame(['a', 'b', 'c'], $this->ledger->notes(), 'the commit made the rows visible');
        $this->assertFalse($this->connection->inTransaction());
    }

    public function testRejectInTheSecondOfThreeNodesRollsBackAndEndsTheRun(): void
    {
        $built = [];

        $outcome = $this->runnerOn()->run(
            $this->chainOfThree(),
            new \stdClass(),
            null,
            $this->factory([
                self::A => $this->writes('a'),
                self::B => $this->writesThenRejects('b', ResponseStatus::NotFound, 'p.gone'),
                self::C => $this->writes('c'),
            ], $built)
        );

        $this->assertSame(ResponseStatus::NotFound, $outcome->status(), 'the reject status is the status of the run');
        $this->assertSame([self::A, self::B], $built, 'the third node never runs');
        $this->assertSame([], $this->ledger->notes(), 'the row state is rolled back');
        $this->assertSame(['beginTransaction', 'rollback'], $this->connection->calls());
    }

    public function testBracketRollsBackAThrowAndThrowsItOn(): void
    {
        $bracket = new Bracket($this->connection);
        $request = RunRequest::of($this->chainOfThree(), new \stdClass(), null, $this->factory([]));

        try {
            $bracket($request, function (RunRequest $r): ProcessOutcome {
                $this->ledger->add('lost');

                throw new \DomainException('boom');
            });
            $this->fail('the exception must be thrown on');
        } catch (\DomainException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $this->assertSame([], $this->ledger->notes());
        $this->assertSame(['beginTransaction', 'rollback'], $this->connection->calls());
        $this->assertFalse($this->connection->inTransaction());
    }

    public function testOptOutRunsWithoutAnyTransactionAndRejectKeepsEarlierRows(): void
    {
        $definition = ProcessDefinition::for('In', 'P')
            ->transactional(false)
            ->node(self::A)->onTrue(self::B)
            ->node(self::B)->terminal()
            ->build();

        $outcome = $this->runnerOn()->run(
            $definition,
            new \stdClass(),
            null,
            $this->factory([
                self::A => $this->writes('a'),
                self::B => $this->writesThenRejects('b', ResponseStatus::Conflict, 'p.clash'),
            ])
        );

        $this->assertSame(ResponseStatus::Conflict, $outcome->status());
        $this->assertSame([], $this->connection->calls(), '0x beginTransaction, nothing else either');
        $this->assertSame(['a', 'b'], $this->ledger->notes(), 'nothing was rolled back');
    }

    public function testTopLevelRunInAnOpenTransactionWorksInASavepointAndLeavesTheTransactionOpen(): void
    {
        $this->connection->pdo()->beginTransaction();
        $this->ledger->add('outer');

        $outcome = $this->runnerOn()->run(
            $this->chainOfThree(),
            new \stdClass(),
            null,
            $this->factory([
                self::A => $this->writes('a'),
                self::B => $this->writes('b'),
                self::C => $this->writes('c'),
            ])
        );

        $this->assertSame(ResponseStatus::Success, $outcome->status());
        $this->assertSame(['savepoint:sp_0', 'release:sp_0'], $this->connection->calls());
        $this->assertSame(0, $this->connection->count('beginTransaction'));
        $this->assertTrue($this->connection->inTransaction(), 'the holder of the transaction decides');
        $this->connection->pdo()->rollBack();
        $this->assertSame([], $this->ledger->notes(), 'the work belonged to the surrounding transaction');
    }

    public function testRejectInAnOpenTransactionRollsBackOnlyToTheSavepoint(): void
    {
        $this->connection->pdo()->beginTransaction();
        $this->ledger->add('outer');

        $outcome = $this->runnerOn()->run(
            $this->chainOfThree(),
            new \stdClass(),
            null,
            $this->factory([
                self::A => $this->writes('a'),
                self::B => $this->writesThenRejects('b', ResponseStatus::RuleViolation, 'p.rule'),
            ])
        );

        $this->assertSame(ResponseStatus::RuleViolation, $outcome->status());
        $this->assertSame(['savepoint:sp_0', 'rollbackTo:sp_0'], $this->connection->calls());
        $this->assertTrue($this->connection->inTransaction());
        $this->connection->pdo()->commit();
        $this->assertSame(['outer'], $this->ledger->notes());
    }

    public function testSubProcessesWorkInSavepointsNamedByDepthAndNeverBeginATransaction(): void
    {
        $runner = $this->runnerOn();
        $deep = ProcessDefinition::for('In', 'Deep')->node(self::DEEP)->terminal()->build();
        $innerNodes = $this->factory([
            self::INNER_A => $this->subProcess($runner, $deep, $this->factory([self::DEEP => $this->writes('deep')])),
            self::INNER_B => $this->writes('inner'),
        ]);
        $definition = ProcessDefinition::for('In', 'Outer')->node(self::A)->terminal()->build();

        $outcome = $runner->run(
            $definition,
            new \stdClass(),
            null,
            $this->factory([self::A => $this->subProcess($runner, $this->inner(), $innerNodes)])
        );

        $this->assertSame(ResponseStatus::Success, $outcome->status());
        $this->assertSame(
            ['beginTransaction', 'savepoint:sp_1', 'savepoint:sp_2', 'release:sp_2', 'release:sp_1', 'commit'],
            $this->connection->calls(),
            'depth 1 is sp_1, depth 2 is sp_2; the one transaction is the top-level one'
        );
        $this->assertSame(1, $this->connection->count('beginTransaction'));
        $this->assertSame(['deep', 'inner'], $this->ledger->notes());
    }

    public function testRejectedSubProcessRollsBackOnlyItselfAndTheOuterRunCommitsOnADevOnFalse(): void
    {
        $runner = $this->runnerOn();
        $definition = ProcessDefinition::for('In', 'Outer')
            ->node(self::A)->onTrue(self::B)
            ->node(self::B)->onTrue(self::C)->onFalse(self::C)
            ->node(self::C)->terminal()
            ->build();
        $innerNodes = $this->factory([
            self::INNER_A => $this->writes('inner-a'),
            self::INNER_B => $this->writesThenRejects('inner-b', ResponseStatus::RuleViolation, 'inner.rule'),
        ]);

        $outcome = $runner->run(
            $definition,
            new \stdClass(),
            null,
            $this->factory([
                self::A => $this->writes('outer-a'),
                self::B => $this->subProcess($runner, $this->inner(), $innerNodes, Route::OnFalse),
                self::C => $this->writes('outer-c'),
            ])
        );

        $this->assertSame(ResponseStatus::Success, $outcome->status());
        $this->assertSame(['outer-a', 'outer-c'], $this->ledger->notes(), 'only the inner work is gone');
        $this->assertSame(
            ['beginTransaction', 'savepoint:sp_1', 'rollbackTo:sp_1', 'commit'],
            $this->connection->calls()
        );
    }

    public function testOuterOutcomeCarriesTheEventsOfTheInnerRun(): void
    {
        $runner = $this->runnerOn();
        $innerEvent = new \stdClass();
        $outerEvent = new \stdClass();
        $definition = ProcessDefinition::for('In', 'Outer')
            ->node(self::A)->onTrue(self::B)
            ->node(self::B)->terminal()
            ->build();
        $innerNodes = $this->factory([
            self::INNER_A => static fn(ProcessContext $c): NodeResult => NodeResult::ok(['n' => 1])->withEvents($innerEvent),
            self::INNER_B => static fn(ProcessContext $c): NodeResult => NodeResult::ok(['n' => 2]),
        ]);

        $outcome = $runner->run(
            $definition,
            new \stdClass(),
            null,
            $this->factory([
                self::A => $this->subProcess($runner, $this->inner(), $innerNodes),
                self::B => static fn(ProcessContext $c): NodeResult => NodeResult::ok(['done' => true])->withEvents($outerEvent),
            ])
        );

        $this->assertSame([$innerEvent, $outerEvent], $outcome->events());
    }

    public function testInnerValidationFailureIsRejectedWith400AtTheOuterCall(): void
    {
        $runner = $this->runnerOn();
        $inner = ProcessDefinition::for(OrderInput::class, 'Inner')
            ->validate(OrderValidation::class)
            ->node(self::INNER_A)->terminal()
            ->build();
        $definition = ProcessDefinition::for('In', 'Outer')->node(self::A)->terminal()->build();

        $outcome = $runner->run(
            $definition,
            new \stdClass(),
            null,
            $this->factory([self::A => $this->subProcess(
                $runner,
                $inner,
                $this->factory([self::INNER_A => $this->writes('inner')]),
                null,
                new OrderInput(null, [])
            )])
        );

        $this->assertSame(ResponseStatus::ValidationError, $outcome->status());
        $form = $outcome->response();
        $this->assertInstanceOf(ValidationFailure::class, $form);
        $this->assertSame('sub.rejected', $form->messageKey());
        $this->assertSame(['beginTransaction', 'rollback'], $this->connection->calls(), 'the inner run never got a bracket');
        $this->assertSame([], $this->ledger->notes());
    }

    public function testLoopLimitCountsEachRunOnItsOwn(): void
    {
        $runner = $this->runnerOn();
        $definition = ProcessDefinition::for('In', 'Outer')
            ->loopLimit(3)
            ->node(self::A)->onTrue(self::B)
            ->node(self::B)->onTrue(self::C)
            ->node(self::C)->terminal()
            ->build();
        $inner = ProcessDefinition::for('In', 'Inner')
            ->loopLimit(2)
            ->node(self::INNER_A)->onTrue(self::INNER_B)
            ->node(self::INNER_B)->terminal()
            ->build();
        $ok = static fn(ProcessContext $c): NodeResult => NodeResult::ok(['n' => 1]);
        $innerNodes = $this->factory([self::INNER_A => $ok, self::INNER_B => $ok]);

        $outcome = $runner->run(
            $definition,
            new \stdClass(),
            null,
            $this->factory([
                self::A => $ok,
                self::B => $this->subProcess($runner, $inner, $innerNodes),
                self::C => $ok,
            ])
        );

        $this->assertSame(ResponseStatus::Success, $outcome->status(), '3 + 2 steps, yet no run exceeds its own limit');
    }
}
