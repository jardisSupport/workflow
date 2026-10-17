<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Tests\Support\Db;

use JardisSupport\Contract\DbConnection\DbConnectionInterface;
use JardisSupport\Contract\DbConnection\SavepointInterface;
use JardisSupport\Repository\Adapter\PdoConnection;
use PDO;

/**
 * A real SQLite connection (repository's PdoConnection) that notes every transaction call it receives.
 *
 * Behaviour is the real one; the log only answers "how often was X called" (a call that must
 * NOT happen leaves no row state to look at).
 */
final class RecordingConnection implements DbConnectionInterface, SavepointInterface
{
    /** @var list<string> */
    private array $calls = [];

    private PdoConnection $inner;

    public function __construct(PDO $pdo)
    {
        $this->inner = new PdoConnection($pdo);
    }

    /**
     * @return list<string>
     */
    public function calls(): array
    {
        return $this->calls;
    }

    public function count(string $call): int
    {
        return count(array_filter($this->calls, static fn(string $entry): bool => $entry === $call));
    }

    public function connect(): void
    {
        $this->inner->connect();
    }

    public function pdo(): PDO
    {
        return $this->inner->pdo();
    }

    public function isConnected(): bool
    {
        return $this->inner->isConnected();
    }

    public function disconnect(): void
    {
        $this->inner->disconnect();
    }

    public function reconnect(): void
    {
        $this->inner->reconnect();
    }

    public function beginTransaction(): void
    {
        $this->calls[] = 'beginTransaction';
        $this->inner->beginTransaction();
    }

    public function commit(): void
    {
        $this->calls[] = 'commit';
        $this->inner->commit();
    }

    public function rollback(): void
    {
        $this->calls[] = 'rollback';
        $this->inner->rollback();
    }

    public function inTransaction(): bool
    {
        return $this->inner->inTransaction();
    }

    public function getDriverName(): string
    {
        return $this->inner->getDriverName();
    }

    public function getDatabaseName(): string
    {
        return $this->inner->getDatabaseName();
    }

    public function savepoint(string $name): void
    {
        $this->calls[] = 'savepoint:' . $name;
        $this->inner->savepoint($name);
    }

    public function releaseSavepoint(string $name): void
    {
        $this->calls[] = 'release:' . $name;
        $this->inner->releaseSavepoint($name);
    }

    public function rollbackToSavepoint(string $name): void
    {
        $this->calls[] = 'rollbackTo:' . $name;
        $this->inner->rollbackToSavepoint($name);
    }
}
