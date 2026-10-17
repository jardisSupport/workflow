<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Tests\Support\Process\Order;

/**
 * Process input with a nested list: the shape that gives field paths like `items[1].qty`.
 */
final class OrderInput
{
    /**
     * @param list<OrderLine> $items
     */
    public function __construct(public ?string $customer, public array $items)
    {
    }
}
