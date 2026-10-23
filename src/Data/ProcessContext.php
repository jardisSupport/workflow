<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Data;

/**
 * Read-only view of one process run: input and the immutable chain of node results.
 *
 * The chain never changes in place - the engine hands every step a new context
 * ({@see self::withStep()}). The class is deliberately not final: generated processes
 * extend it with typed getters (documented exception to the no-inheritance rule).
 */
class ProcessContext
{
    /** @var list<array{node: string, result: NodeResult}> */
    private array $steps = [];

    public function __construct(
        private readonly object $input,
        private readonly int $depth = 0,
    ) {
    }

    public function input(): object
    {
        return $this->input;
    }

    /** Result of the most recently executed node. */
    public function previous(): ?NodeResult
    {
        $last = $this->steps[count($this->steps) - 1] ?? null;

        return $last['result'] ?? null;
    }

    /** Most recent result of the given node. */
    public function latest(string $node): ?NodeResult
    {
        $all = $this->all($node);

        return $all[count($all) - 1] ?? null;
    }

    /**
     * All results of the given node in execution order.
     *
     * @return list<NodeResult>
     */
    public function all(string $node): array
    {
        $results = [];
        foreach ($this->steps as $step) {
            if ($step['node'] === $node) {
                $results[] = $step['result'];
            }
        }

        return $results;
    }

    /** How many times the given node has already been executed in this run. */
    public function iteration(string $node): int
    {
        return count($this->all($node));
    }

    /**
     * The full execution chain in order.
     *
     * @return list<array{node: string, result: NodeResult}>
     */
    public function chain(): array
    {
        return $this->steps;
    }

    /** Nesting depth of the run (0 = outermost). */
    public function depth(): int
    {
        return $this->depth;
    }

    /**
     * Returns a clone extended by one step; the original stays unchanged.
     *
     * @internal Only the engine advances the chain.
     */
    public function withStep(string $node, NodeResult $result): static
    {
        $next = clone $this;
        $next->steps[] = ['node' => $node, 'result' => $result];

        return $next;
    }
}
