<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Tests\Support\Db;

use PDO;

/**
 * The row state the transaction tests look at: one table, one note per row.
 */
final class Ledger
{
    public function __construct(private readonly PDO $pdo)
    {
        $this->pdo->exec('CREATE TABLE ledger (id INTEGER PRIMARY KEY AUTOINCREMENT, note TEXT NOT NULL)');
    }

    public static function pdo(): PDO
    {
        return new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }

    public function add(string $note): void
    {
        $this->pdo->prepare('INSERT INTO ledger (note) VALUES (?)')->execute([$note]);
    }

    /**
     * @return list<string>
     */
    public function notes(): array
    {
        $rows = $this->pdo->query('SELECT note FROM ledger ORDER BY id');

        return $rows === false ? [] : array_map('strval', $rows->fetchAll(PDO::FETCH_COLUMN));
    }
}
