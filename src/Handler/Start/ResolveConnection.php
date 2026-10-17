<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Handler\Start;

use JardisSupport\Contract\DbConnection\ConnectionPoolInterface;
use JardisSupport\Contract\DbConnection\DbConnectionInterface;
use JardisSupport\Contract\Kernel\DomainKernelInterface;
use JardisSupport\Repository\Adapter\PdoConnection;

/**
 * Resolves the writer connection of a run from the kernel.
 *
 * `DomainKernelInterface::dbConnection()` answers a connection pool, a plain PDO or null. A pool
 * gives its writer, a PDO is wrapped by the repository's `PdoConnection` (transactions and
 * savepoints), no database gives null - then only a process that opted out of the transaction runs.
 */
final class ResolveConnection
{
    public function __invoke(DomainKernelInterface $kernel): ?DbConnectionInterface
    {
        $connection = $kernel->dbConnection();

        return match (true) {
            $connection instanceof ConnectionPoolInterface => $connection->getWriter(),
            $connection instanceof \PDO => new PdoConnection($connection),
            default => null,
        };
    }
}
