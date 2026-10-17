<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Tests\Support\Process\Order;

/**
 * One line of the order input.
 */
final class OrderLine
{
    public function __construct(public string $sku, public int $qty)
    {
    }
}
