<?php

declare(strict_types=1);

namespace JardisSupport\Workflow;

use JardisSupport\Contract\Workflow\WorkflowContextInterface;
use JardisSupport\Contract\Workflow\WorkflowResultInterface;

/**
 * Mutable execution context passed through a workflow's handler chain.
 *
 * Stores every handler invocation as an entry in an ordered execution log.
 * Re-invocations of the same handler — whether from a retry loop or a
 * cross-branch revisit — append a new entry instead of overwriting earlier
 * ones, so the full history is preserved.
 *
 * The most recently appended result is exposed as the "previous" result for
 * the next handler. FQCN-based lookups are secondary and resolve against the
 * log via getLatest() / getAll().
 */
final class WorkflowContext implements WorkflowContextInterface
{
    /** @var list<array{handler: class-string, result: WorkflowResultInterface}> */
    private array $chain = [];

    private ?WorkflowResultInterface $previous = null;

    public function append(string $handlerFqcn, WorkflowResultInterface $result): void
    {
        $this->chain[] = ['handler' => $handlerFqcn, 'result' => $result];
        $this->previous = $result;
    }

    public function getPrevious(): ?WorkflowResultInterface
    {
        return $this->previous;
    }

    public function getLatest(string $handlerFqcn): ?WorkflowResultInterface
    {
        for ($i = count($this->chain) - 1; $i >= 0; $i--) {
            if ($this->chain[$i]['handler'] === $handlerFqcn) {
                return $this->chain[$i]['result'];
            }
        }

        return null;
    }

    /**
     * @return list<WorkflowResultInterface>
     */
    public function getAll(string $handlerFqcn): array
    {
        $results = [];
        foreach ($this->chain as $entry) {
            if ($entry['handler'] === $handlerFqcn) {
                $results[] = $entry['result'];
            }
        }

        return $results;
    }

    /**
     * @return list<array{handler: class-string, result: WorkflowResultInterface}>
     */
    public function getChain(): array
    {
        return $this->chain;
    }
}
