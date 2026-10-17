<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Handler\Transaction;

use JardisSupport\Contract\DbConnection\DbConnectionInterface;
use JardisSupport\Contract\DbConnection\SavepointInterface;
use JardisSupport\Workflow\Data\ProcessOutcome;
use JardisSupport\Workflow\Data\RunRequest;
use JardisSupport\Workflow\Exception\ConfigurationException;

/**
 * Run layer: the transaction bracket around a run.
 *
 * A process that opted out of the transaction runs without a bracket. Otherwise a top-level run
 * without an open transaction opens and ends its own. Inside an open transaction - the one of the
 * surrounding run, or one somebody else holds - the run works in a savepoint `sp_{depth}` and never
 * starts a transaction; a sub-process of a run without a transaction has no bracket either. A
 * success commits (releases the savepoint), an answer of 400 and above or an exception rolls back
 * (to the savepoint) - an exception is thrown on. A savepoint needs a connection that supports
 * them, else it is a configuration error.
 */
final class Bracket
{
    public function __construct(private readonly DbConnectionInterface $connection)
    {
    }

    /**
     * @param \Closure(RunRequest): ProcessOutcome $next
     * @throws ConfigurationException when a savepoint is needed and the connection has no savepoints
     */
    public function __invoke(RunRequest $request, \Closure $next): ProcessOutcome
    {
        if (!$request->definition->isTransactional()) {
            return $next($request);
        }

        if ($this->connection->inTransaction()) {
            return $this->savepoint($request, $next);
        }

        return $request->parent === null ? $this->transaction($request, $next) : $next($request);
    }

    /**
     * @param \Closure(RunRequest): ProcessOutcome $next
     */
    private function transaction(RunRequest $request, \Closure $next): ProcessOutcome
    {
        $this->connection->beginTransaction();

        return $this->settle(
            $request,
            $next,
            fn() => $this->connection->commit(),
            fn() => $this->connection->rollback()
        );
    }

    /**
     * @param \Closure(RunRequest): ProcessOutcome $next
     */
    private function savepoint(RunRequest $request, \Closure $next): ProcessOutcome
    {
        $connection = $this->connection;
        if (!$connection instanceof SavepointInterface) {
            throw new ConfigurationException(sprintf(
                'Process "%s" runs inside a transaction and needs savepoints, but %s offers none',
                $request->definition->getName(),
                $connection::class
            ));
        }
        $name = 'sp_' . $request->depth;
        $connection->savepoint($name);

        return $this->settle(
            $request,
            $next,
            static fn() => $connection->releaseSavepoint($name),
            static fn() => $connection->rollbackToSavepoint($name)
        );
    }

    /**
     * @param \Closure(RunRequest): ProcessOutcome $next
     * @param \Closure(): void $commit
     * @param \Closure(): void $rollback
     */
    private function settle(RunRequest $request, \Closure $next, \Closure $commit, \Closure $rollback): ProcessOutcome
    {
        try {
            $outcome = $next($request);
        } catch (\Throwable $failure) {
            $rollback();

            throw $failure;
        }

        if ($outcome->status()->value >= 400) {
            $rollback();
        } else {
            $commit();
        }

        return $outcome;
    }
}
