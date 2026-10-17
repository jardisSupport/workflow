<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Tests\Support\Log;

use Psr\Log\AbstractLogger;

/**
 * In-memory PSR-3 logger: keeps every record, optionally fails on write.
 */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    public array $records = [];

    public function __construct(private readonly bool $failing = false)
    {
    }

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        if ($this->failing) {
            throw new \RuntimeException('log sink down');
        }
        $this->records[] = ['level' => (string) $level, 'message' => (string) $message, 'context' => $context];
    }
}
