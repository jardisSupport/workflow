<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Tests\Integration\Failure;

use JardisSupport\Contract\Kernel\ResponseStatus;
use JardisSupport\Contract\Repository\Exception\TransientConflictException;
use JardisSupport\Workflow\Data\Failure\Conflict;
use JardisSupport\Workflow\Data\NodeResult;
use JardisSupport\Workflow\Data\ProcessContext;
use JardisSupport\Workflow\Data\ProcessDefinition;
use JardisSupport\Workflow\Data\Route;
use JardisSupport\Workflow\Exception\ConfigurationException;
use JardisSupport\Workflow\Exception\ConstructionException;
use JardisSupport\Workflow\Exception\LoopLimitExceededException;
use JardisSupport\Workflow\Exception\UnroutedStatusException;
use JardisSupport\Workflow\Tests\Support\Db\PlainConnection;
use JardisSupport\Workflow\Tests\Support\Process\Checkout\Command\ReserveStock;
use JardisSupport\Workflow\Tests\Support\Process\Checkout\Response\ReserveStockResponse;
use JardisSupport\Workflow\Tests\Support\TransactionTestCase;

/**
 * MapThrowable is the outermost layer: one test per row of the table (W11) plus A-N4 and A-S7(6).
 * Each throw rolls back first - the row state shows it.
 */
class MapThrowableTest extends TransactionTestCase
{
    private const A = 'Node\\A';
    private const B = 'Node\\B';
    private const INNER_A = 'Inner\\A';

    private function single(): ProcessDefinition
    {
        return ProcessDefinition::for('In', 'P')->node(self::A)->terminal()->build();
    }

    /**
     * @param class-string<\Throwable> $cause
     */
    private function assertFailsWith500(\JardisSupport\Workflow\Data\ProcessOutcome $outcome, string $cause): void
    {
        $this->assertSame(ResponseStatus::InternalError, $outcome->status());
        $this->assertEquals(new \stdClass(), $outcome->response(), 'no form, an empty object');
        $this->assertInstanceOf($cause, $outcome->cause());
        $this->assertSame([], $outcome->events());
        $this->assertSame([], $this->ledger->notes(), 'rolled back before the outcome');
        $this->assertFalse($this->connection->inTransaction());
    }

    public function testConstructionExceptionAnswers500(): void
    {
        $outcome = $this->runnerOn()->run($this->single(), new \stdClass(), null, $this->factory([
            self::A => function (ProcessContext $c): NodeResult {
                $this->ledger->add('lost');

                throw new ConstructionException('bad');
            },
        ]));

        $this->assertFailsWith500($outcome, ConstructionException::class);
    }

    public function testConfigurationExceptionAnswers500(): void
    {
        $outcome = $this->runnerOn()->run($this->single(), new \stdClass(), null, $this->factory([
            self::A => function (ProcessContext $c): NodeResult {
                $this->ledger->add('lost');

                throw new ConfigurationException('no auth');
            },
        ]));

        $this->assertFailsWith500($outcome, ConfigurationException::class);
    }

    public function testLoopLimitExceededAnswers500(): void
    {
        $definition = ProcessDefinition::for('In', 'P')->loopLimit(2)->node(self::A)->onTrue(self::A)->build();

        $outcome = $this->runnerOn()->run($definition, new \stdClass(), null, $this->factory([
            self::A => $this->writes('lost', ['n' => 1]),
        ]));

        $this->assertFailsWith500($outcome, LoopLimitExceededException::class);
    }

    public function testUnroutedStatusAnswers500(): void
    {
        $definition = ProcessDefinition::for('In', 'P')->node(self::A)->onTrue(null)->build();

        $outcome = $this->runnerOn()->run($definition, new \stdClass(), null, $this->factory([
            self::A => function (ProcessContext $c): NodeResult {
                $this->ledger->add('lost');

                return NodeResult::route(Route::OnFalse);
            },
        ]));

        $this->assertFailsWith500($outcome, UnroutedStatusException::class);
    }

    public function testAnyOtherThrowableAnswers500(): void
    {
        $outcome = $this->runnerOn()->run($this->single(), new \stdClass(), null, $this->factory([
            self::A => function (ProcessContext $c): NodeResult {
                $this->ledger->add('lost');

                throw new \DomainException('anything');
            },
        ]));

        $this->assertFailsWith500($outcome, \DomainException::class);
        $this->assertSame('anything', $outcome->cause()?->getMessage());
    }

    public function testAnErrorIsMappedLikeAnyOtherThrowable(): void
    {
        $outcome = $this->runnerOn()->run($this->single(), new \stdClass(), null, $this->factory([
            self::A => static fn(ProcessContext $c): NodeResult => throw new \TypeError('wrong type'),
        ]));

        $this->assertFailsWith500($outcome, \TypeError::class);
    }

    public function testNestedRunWithoutSavepointSupportIsAConfigurationError(): void
    {
        $plain = new PlainConnection($this->connection->pdo());
        $runner = $this->runnerOn($plain);
        $inner = ProcessDefinition::for('In', 'Inner')->node(self::INNER_A)->terminal()->build();
        $definition = ProcessDefinition::for('In', 'Outer')->node(self::A)->terminal()->build();

        $outcome = $runner->run($definition, new \stdClass(), null, $this->factory([
            self::A => $this->subProcess($runner, $inner, $this->factory([self::INNER_A => $this->writes('lost')])),
        ]));

        $this->assertFailsWith500($outcome, ConfigurationException::class);
        $this->assertStringContainsString('savepoints', (string) $outcome->cause()?->getMessage());
    }

    public function testTopLevelRunInAnOpenTransactionWithoutSavepointSupportIsAConfigurationError(): void
    {
        $plain = new PlainConnection($this->connection->pdo());
        $plain->beginTransaction();

        $outcome = $this->runnerOn($plain)->run($this->single(), new \stdClass(), null, $this->factory([
            self::A => $this->writes('never'),
        ]));

        $this->assertSame(ResponseStatus::InternalError, $outcome->status());
        $this->assertInstanceOf(ConfigurationException::class, $outcome->cause());
        $this->assertSame([], $this->ledger->notes(), 'the node never ran');
        $plain->rollback();
    }

    public function testTransientConflictInTheOwningRunAnswers409(): void
    {
        $conflict = new TransientConflictException('deadlock', '40001');

        $outcome = $this->runnerOn()->run($this->single(), new \stdClass(), null, $this->factory([
            self::A => function (ProcessContext $c) use ($conflict): NodeResult {
                $this->ledger->add('lost');

                throw $conflict;
            },
        ]));

        $this->assertSame(ResponseStatus::Conflict, $outcome->status());
        $this->assertSame($conflict, $outcome->cause());
        $form = $outcome->response();
        $this->assertInstanceOf(Conflict::class, $form);
        $this->assertSame('process.transientConflict', $form->messageKey());
        $this->assertSame('stale_write', $form->reason());
        $this->assertSame('P', $form->aggregate(), 'the process name, as the pre-move handler answered');
        $this->assertNull($outcome->declaredMessageKey(), 'a key of the package is never declared');
        $this->assertEquals(
            ['@type' => 'concurrencyConflict', 'reason' => 'stale_write', 'aggregate' => 'P', 'context' => new \stdClass(), 'violated' => []],
            (new \JardisSupport\Workflow\Handler\Response\BuildResponseData())($outcome, 'P')
        );
        $this->assertSame([], $this->ledger->notes());
        $this->assertSame(['beginTransaction', 'rollback'], $this->connection->calls());
    }

    public function testNestedTransientConflictIsThrownOnAndTheOwningRunAnswers409(): void
    {
        $runner = $this->runnerOn();
        $conflict = new TransientConflictException('snapshot', '1020');
        $inner = ProcessDefinition::for('In', 'Inner')->node(self::INNER_A)->terminal()->build();
        $definition = ProcessDefinition::for('In', 'Outer')->node(self::A)->terminal()->build();

        $outcome = $runner->run($definition, new \stdClass(), null, $this->factory([
            self::A => $this->subProcess($runner, $inner, $this->factory([
                self::INNER_A => function (ProcessContext $c) use ($conflict): NodeResult {
                    $this->ledger->add('lost');

                    throw $conflict;
                },
            ])),
        ]));

        $this->assertSame(ResponseStatus::Conflict, $outcome->status());
        $this->assertSame($conflict, $outcome->cause(), 'the very exception of the inner run, unchanged');
        $this->assertSame([], $this->ledger->notes());
        $this->assertSame(
            ['beginTransaction', 'savepoint:sp_1', 'rollbackTo:sp_1', 'rollback'],
            $this->connection->calls(),
            'the inner bracket rolled back to its savepoint, the owner rolled back its transaction'
        );
    }

    public function testNestedRunThrowsTheConflictToItsCaller(): void
    {
        $runner = $this->runnerOn();
        $definition = ProcessDefinition::for('In', 'Inner')->node(self::INNER_A)->terminal()->build();
        $parent = new ProcessContext(new \stdClass(), null, 0);

        $this->expectException(TransientConflictException::class);

        $runner->run($definition, new \stdClass(), null, $this->factory([
            self::INNER_A => static fn(ProcessContext $c): NodeResult => throw new TransientConflictException('x'),
        ]), $parent);
    }

    public function testMissingRequiredResponseFieldAnswers500(): void
    {
        $definition = ProcessDefinition::for('In', 'Checkout')
            ->node(ReserveStock::class)->response(ReserveStockResponse::class, 'reservationId')->terminal()
            ->build();

        $outcome = $this->runnerOn()->run($definition, new \stdClass(), null, $this->factory([
            ReserveStock::class => function (ProcessContext $c): NodeResult {
                $this->ledger->add('lost');

                return NodeResult::ok(['reservationId' => 'R-1']);
            },
        ]));

        $this->assertFailsWith500($outcome, ConstructionException::class);
        $this->assertStringContainsString('quantity', (string) $outcome->cause()?->getMessage());
    }
}
