<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Data;

/**
 * Validated routing graph: ordered nodes, each with its transition map (route key => target node or null).
 *
 * The first node is the entry node. A null target is a declared terminal end.
 *
 * @internal Only {@see ProcessDefinition} builds it, only the engine reads it.
 */
final readonly class WorkflowConfig
{
    /**
     * @param non-empty-array<string, array<string, string|null>> $transitions node => (route key => target)
     */
    public function __construct(private array $transitions)
    {
    }

    public function entry(): string
    {
        return array_key_first($this->transitions);
    }

    /**
     * @return list<string>
     */
    public function nodes(): array
    {
        return array_keys($this->transitions);
    }

    public function has(string $node): bool
    {
        return array_key_exists($node, $this->transitions);
    }

    /**
     * @return array<string, string|null>
     */
    public function transitions(string $node): array
    {
        return $this->transitions[$node] ?? [];
    }
}
