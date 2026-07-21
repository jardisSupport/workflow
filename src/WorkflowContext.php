<?php

declare(strict_types=1);

namespace JardisSupport\Workflow;

use JardisSupport\Contract\Workflow\WorkflowContextInterface;
use JardisSupport\Contract\Workflow\WorkflowResultInterface;

/**
 * Mutable execution context passed through a workflow's handler chain.
 *
 * Stores every handler invocation as a flat, handler-stamped entry in an
 * ordered execution log. Re-invocations of the same handler — whether from
 * a retry loop or a cross-branch revisit — append a new entry instead of
 * overwriting earlier ones, so the full history is preserved.
 *
 * The most recently appended result is exposed as the "previous" result for
 * the next handler. FQCN-based lookups iterate over the flat list via
 * {@see WorkflowResultInterface::getHandlerFqcn()}.
 *
 * Three opaque slots — reference/response/exception — carry mantle state
 * between the flow's entry/final companions and the routing graph.
 */
final class WorkflowContext implements WorkflowContextInterface
{
    /** @var list<WorkflowResultInterface> */
    private array $chain = [];

    private ?WorkflowResultInterface $previous = null;

    private mixed $reference = null;

    private mixed $response = null;

    private ?\Throwable $exception = null;

    public function append(string $handlerFqcn, WorkflowResultInterface $result): void
    {
        $stamped = $result->withHandler($handlerFqcn);
        $this->chain[] = $stamped;
        $this->previous = $stamped;
    }

    public function getPrevious(): ?WorkflowResultInterface
    {
        return $this->previous;
    }

    public function getLatest(string $handlerFqcn): ?WorkflowResultInterface
    {
        for ($i = count($this->chain) - 1; $i >= 0; $i--) {
            if ($this->chain[$i]->getHandlerFqcn() === $handlerFqcn) {
                return $this->chain[$i];
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
            if ($entry->getHandlerFqcn() === $handlerFqcn) {
                $results[] = $entry;
            }
        }

        return $results;
    }

    /**
     * @return list<WorkflowResultInterface>
     */
    public function getChain(): array
    {
        return $this->chain;
    }

    public function reference(): mixed
    {
        return $this->reference;
    }

    public function setReference(mixed $value): void
    {
        $this->reference = $value;
    }

    public function response(): mixed
    {
        return $this->response;
    }

    public function setResponse(mixed $value): void
    {
        $this->response = $value;
    }

    public function getException(): ?\Throwable
    {
        return $this->exception;
    }

    public function setException(\Throwable $e): void
    {
        $this->exception = $e;
    }
}
