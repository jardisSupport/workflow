<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Data;

use JardisSupport\Workflow\Exception\ConstructionException;

/**
 * Typed read answer: a list of items with the total count, or a single optional item.
 *
 * @template T
 */
final readonly class ReadResult
{
    /**
     * @param list<T> $items
     */
    private function __construct(
        private array $items,
        private int $total,
    ) {
    }

    /**
     * @template U
     * @param list<U> $items
     * @return self<U>
     * @throws ConstructionException when $total is smaller than the number of items
     */
    public static function ofList(array $items, int $total): self
    {
        if ($total < count($items)) {
            throw new ConstructionException(sprintf(
                'Total %d is smaller than the %d given items',
                $total,
                count($items)
            ));
        }

        return new self($items, $total);
    }

    /**
     * @template U
     * @param U|null $item
     * @return self<U>
     */
    public static function ofOne(mixed $item): self
    {
        /** @var list<U> $items */
        $items = $item === null ? [] : [$item];

        return new self($items, count($items));
    }

    /**
     * @return list<T>
     */
    public function items(): array
    {
        return $this->items;
    }

    public function total(): int
    {
        return $this->total;
    }

    /**
     * @return T|null
     */
    public function first(): mixed
    {
        return $this->items[0] ?? null;
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }
}
