<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Tests\Support\Process\Checkout\Response;

/**
 * Typed answer of the fake node: two required fields and one optional.
 */
final readonly class ReserveStockResponse
{
    public function __construct(public string $reservationId, public int $quantity, public string $note = 'none')
    {
    }
}
