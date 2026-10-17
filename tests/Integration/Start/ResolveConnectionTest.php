<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Tests\Integration\Start;

use JardisSupport\Contract\DbConnection\ConnectionPoolInterface;
use JardisSupport\Contract\DbConnection\DbConnectionInterface;
use JardisSupport\Contract\DbConnection\SavepointInterface;
use JardisSupport\Contract\Kernel\DomainKernelInterface;
use JardisSupport\Repository\Adapter\PdoConnection;
use JardisSupport\Repository\Adapter\PdoConnectionPool;
use JardisSupport\Workflow\Handler\Start\ResolveConnection;
use JardisSupport\Workflow\Tests\Support\Db\Ledger;
use PHPUnit\Framework\TestCase;

/**
 * The writer connection of a run comes from the kernel's `dbConnection()`: pool, plain PDO or none.
 */
class ResolveConnectionTest extends TestCase
{
    private function kernel(ConnectionPoolInterface|\PDO|null $connection): DomainKernelInterface
    {
        $kernel = $this->createStub(DomainKernelInterface::class);
        $kernel->method('dbConnection')->willReturn($connection);

        return $kernel;
    }

    public function testPoolAnswersItsWriter(): void
    {
        $pool = new PdoConnectionPool(Ledger::pdo());

        $connection = (new ResolveConnection())($this->kernel($pool));

        $this->assertSame($pool->getWriter(), $connection);
    }

    public function testPlainPdoIsWrappedAsTransactionalConnectionWithSavepoints(): void
    {
        $pdo = Ledger::pdo();

        $connection = (new ResolveConnection())($this->kernel($pdo));

        $this->assertInstanceOf(PdoConnection::class, $connection);
        $this->assertInstanceOf(DbConnectionInterface::class, $connection);
        $this->assertInstanceOf(SavepointInterface::class, $connection);
        $this->assertSame($pdo, $connection->pdo());
    }

    public function testNoDatabaseAnswersNull(): void
    {
        $this->assertNull((new ResolveConnection())($this->kernel(null)));
    }
}
