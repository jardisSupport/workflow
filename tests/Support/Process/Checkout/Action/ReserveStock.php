<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Tests\Support\Process\Checkout\Action;

use JardisSupport\Contract\Kernel\DomainResponseInterface;
use JardisSupport\Workflow\Tests\Support\FakeDomainResponse;

/**
 * Fake callee: answers with a reservation, as the aggregate API would.
 */
final class ReserveStock
{
    public function __invoke(): DomainResponseInterface
    {
        return new FakeDomainResponse(201, ['reservationId' => 'R-1', 'quantity' => 3, 'internal' => 'hidden']);
    }
}
