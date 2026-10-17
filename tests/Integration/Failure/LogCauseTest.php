<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Tests\Integration\Failure;

use JardisSupport\Contract\Kernel\ResponseStatus;
use JardisSupport\Contract\Repository\Exception\TransientConflictException;
use JardisSupport\Workflow\Data\NodeResult;
use JardisSupport\Workflow\Data\ProcessContext;
use JardisSupport\Workflow\Data\ProcessDefinition;
use JardisSupport\Workflow\ProcessRunner;
use JardisSupport\Workflow\Tests\Support\Log\RecordingLogger;
use JardisSupport\Workflow\Tests\Support\Process\Order\OrderValidation;
use JardisSupport\Workflow\Tests\Support\TransactionTestCase;

/**
 * The cause of a 5xx answer reaches the logger exactly once, in the owning run only (E3 B).
 */
class LogCauseTest extends TransactionTestCase
{
    private const A = 'Node\\A';
    private const INNER_A = 'Inner\\A';

    private function single(): ProcessDefinition
    {
        return ProcessDefinition::for('In', 'PlaceOrder')->node(self::A)->terminal()->build();
    }

    private function runnerWith(?RecordingLogger $logger): ProcessRunner
    {
        return new ProcessRunner($this->connection, OrderValidation::validator(), $logger);
    }

    public function testA500LogsItsCauseOnceWithProcessName(): void
    {
        $logger = new RecordingLogger();
        $failure = new \RuntimeException('disk full');

        $outcome = $this->runnerWith($logger)->run($this->single(), new \stdClass(), null, $this->factory([
            self::A => static fn(ProcessContext $c): NodeResult => throw $failure,
        ]));

        $this->assertSame(ResponseStatus::InternalError, $outcome->status());
        $this->assertCount(1, $logger->records);
        $this->assertSame('error', $logger->records[0]['level']);
        $this->assertSame('disk full', $logger->records[0]['message']);
        $this->assertSame($failure, $logger->records[0]['context']['exception']);
        $this->assertSame('PlaceOrder', $logger->records[0]['context']['process']);
    }

    public function testWithoutALoggerA500RunsAsBefore(): void
    {
        $outcome = $this->runnerWith(null)->run($this->single(), new \stdClass(), null, $this->factory([
            self::A => static fn(ProcessContext $c): NodeResult => throw new \RuntimeException('x'),
        ]));

        $this->assertSame(ResponseStatus::InternalError, $outcome->status());
        $this->assertInstanceOf(\RuntimeException::class, $outcome->cause());
    }

    public function testASuccessAndARejectLogNothing(): void
    {
        $logger = new RecordingLogger();
        $runner = $this->runnerWith($logger);

        $ok = $runner->run($this->single(), new \stdClass(), null, $this->factory([self::A => $this->writes('a')]));
        $rejected = $runner->run($this->single(), new \stdClass(), null, $this->factory([
            self::A => $this->writesThenRejects('b', ResponseStatus::RuleViolation, 'order.rule'),
        ]));

        $this->assertSame(ResponseStatus::Success, $ok->status());
        $this->assertSame(ResponseStatus::RuleViolation, $rejected->status());
        $this->assertSame([], $logger->records);
    }

    public function testAStaleWriteConflictIsNotAnErrorAndLogsNothing(): void
    {
        $logger = new RecordingLogger();

        $outcome = $this->runnerWith($logger)->run($this->single(), new \stdClass(), null, $this->factory([
            self::A => static fn(ProcessContext $c): NodeResult => throw new TransientConflictException('deadlock', '40001'),
        ]));

        $this->assertSame(ResponseStatus::Conflict, $outcome->status());
        $this->assertNotNull($outcome->cause());
        $this->assertSame([], $logger->records);
    }

    public function testAFailureInASubProcessIsLoggedOnceByTheOwningRun(): void
    {
        $logger = new RecordingLogger();
        $runner = $this->runnerWith($logger);
        $inner = ProcessDefinition::for('In', 'Inner')->node(self::INNER_A)->terminal()->build();
        $outer = ProcessDefinition::for('In', 'Outer')->node(self::A)->terminal()->build();
        $failure = new \RuntimeException('inner broke');

        $outcome = $runner->run($outer, new \stdClass(), null, $this->factory([
            self::A => $this->subProcess($runner, $inner, $this->factory([
                self::INNER_A => static fn(ProcessContext $c): NodeResult => throw $failure,
            ])),
        ]));

        $this->assertSame(ResponseStatus::InternalError, $outcome->status());
        $this->assertSame($failure, $outcome->cause());
        $this->assertCount(1, $logger->records, 'the nested run does not log the cause the owner logs');
        $this->assertSame('Outer', $logger->records[0]['context']['process']);
        $this->assertSame($failure, $logger->records[0]['context']['exception']);
    }

    public function testAFailingLoggerNeverReplacesTheAnswer(): void
    {
        $outcome = $this->runnerWith(new RecordingLogger(failing: true))->run(
            $this->single(),
            new \stdClass(),
            null,
            $this->factory([self::A => static fn(ProcessContext $c): NodeResult => throw new \LogicException('real cause')])
        );

        $this->assertSame(ResponseStatus::InternalError, $outcome->status());
        $this->assertSame('real cause', $outcome->cause()?->getMessage());
    }
}
