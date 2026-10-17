<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Tests\Integration\Runner;

use JardisSupport\Contract\DbConnection\ConnectionPoolInterface;
use JardisSupport\Contract\Kernel\DomainKernelInterface;
use JardisSupport\Contract\Kernel\ResponseStatus;
use JardisSupport\Workflow\Data\NodeResult;
use JardisSupport\Workflow\Data\ProcessContext;
use JardisSupport\Workflow\Data\ProcessDefinition;
use JardisSupport\Workflow\Exception\ConfigurationException;
use JardisSupport\Workflow\ProcessRunner;
use JardisSupport\Repository\Adapter\PdoConnectionPool;
use JardisSupport\Workflow\Tests\Support\Db\Ledger;
use JardisSupport\Workflow\Tests\Support\Log\RecordingLogger;
use JardisSupport\Workflow\Tests\Support\Process\Order\OrderValidation;
use PHPUnit\Framework\TestCase;

/**
 * `ProcessRunner::forKernel()` takes the writer from the kernel (pool, plain PDO, none) and the
 * run works on it: commit, rollback, and no database for an opted-out process.
 */
class ForKernelTest extends TestCase
{
    private const A = 'Node\\A';

    private function kernel(ConnectionPoolInterface|\PDO|null $connection, ?RecordingLogger $logger = null): DomainKernelInterface
    {
        $kernel = $this->createStub(DomainKernelInterface::class);
        $kernel->method('dbConnection')->willReturn($connection);
        $kernel->method('logger')->willReturn($logger);

        return $kernel;
    }

    private function definition(bool $transactional = true): ProcessDefinition
    {
        return ProcessDefinition::for('In', 'P')->transactional($transactional)->node(self::A)->terminal()->build();
    }

    /**
     * @return \Closure(class-string): object
     */
    private function writer(Ledger $ledger, bool $reject = false): \Closure
    {
        return static fn(string $class): object => static function (ProcessContext $context) use ($ledger, $reject): NodeResult {
            $ledger->add('row');

            return $reject ? NodeResult::reject(ResponseStatus::RuleViolation, 'p.rule') : NodeResult::ok(['k' => 'v']);
        };
    }

    public function testKernelWithPoolRunsOnTheWriterAndCommits(): void
    {
        $pdo = Ledger::pdo();
        $ledger = new Ledger($pdo);
        $runner = ProcessRunner::forKernel($this->kernel(new PdoConnectionPool($pdo)), OrderValidation::validator());

        $outcome = $runner->run($this->definition(), new \stdClass(), null, $this->writer($ledger));

        $this->assertSame(ResponseStatus::Success, $outcome->status());
        $this->assertSame(['row'], $ledger->notes());
        $this->assertFalse($pdo->inTransaction());
    }

    public function testKernelWithPlainPdoRunsInATransactionAndRollsBackOnReject(): void
    {
        $pdo = Ledger::pdo();
        $ledger = new Ledger($pdo);
        $runner = ProcessRunner::forKernel($this->kernel($pdo), OrderValidation::validator());

        $outcome = $runner->run($this->definition(), new \stdClass(), null, $this->writer($ledger, reject: true));

        $this->assertSame(ResponseStatus::RuleViolation, $outcome->status());
        $this->assertSame([], $ledger->notes(), 'the wrapped PDO was rolled back');
    }

    public function testKernelWithoutDatabaseRefusesATransactionalProcessAndRunsAnOptedOutOne(): void
    {
        $ledger = new Ledger(Ledger::pdo());
        $runner = ProcessRunner::forKernel($this->kernel(null), OrderValidation::validator());

        $refused = $runner->run($this->definition(), new \stdClass(), null, $this->writer($ledger));
        $this->assertSame(ResponseStatus::InternalError, $refused->status());
        $this->assertInstanceOf(ConfigurationException::class, $refused->cause());
        $this->assertSame([], $ledger->notes());

        $outcome = $runner->run($this->definition(false), new \stdClass(), null, $this->writer($ledger));
        $this->assertSame(ResponseStatus::Success, $outcome->status());
        $this->assertSame(['row'], $ledger->notes());
    }

    public function testKernelLoggerReceivesTheCauseOfAFailedRun(): void
    {
        $logger = new RecordingLogger();
        $runner = ProcessRunner::forKernel($this->kernel(null, $logger), OrderValidation::validator());

        $outcome = $runner->run($this->definition(), new \stdClass(), null, $this->writer(new Ledger(Ledger::pdo())));

        $this->assertSame(ResponseStatus::InternalError, $outcome->status());
        $this->assertCount(1, $logger->records);
        $this->assertSame($outcome->cause(), $logger->records[0]['context']['exception']);
        $this->assertSame('P', $logger->records[0]['context']['process']);
    }

    public function testKernelWithoutALoggerLogsNothingAndFailsAsBefore(): void
    {
        $runner = ProcessRunner::forKernel($this->kernel(null), OrderValidation::validator());

        $outcome = $runner->run($this->definition(), new \stdClass(), null, $this->writer(new Ledger(Ledger::pdo())));

        $this->assertSame(ResponseStatus::InternalError, $outcome->status());
    }
}
