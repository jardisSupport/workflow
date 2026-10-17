<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Data;

/**
 * Write-side answer value: the business identifier of an aggregate root plus the
 * references of its children, never the objects themselves.
 */
final readonly class Reference
{
    /**
     * @param array<string, list<int|string>> $children child kind => business identifiers
     */
    public function __construct(
        private int|string $root,
        private array $children = [],
    ) {
    }

    public function root(): int|string
    {
        return $this->root;
    }

    /**
     * @return array<string, list<int|string>>
     */
    public function children(): array
    {
        return $this->children;
    }
}
