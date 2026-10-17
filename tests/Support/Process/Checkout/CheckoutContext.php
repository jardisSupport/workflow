<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Tests\Support\Process\Checkout;

use JardisSupport\Workflow\Data\ProcessContext;

/**
 * Typed context of the fake process: a generated process extends the base like this.
 */
final class CheckoutContext extends ProcessContext
{
    public function marker(): string
    {
        return 'checkout:' . $this->depth();
    }
}
