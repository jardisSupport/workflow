<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Tests\Support\Into;

/**
 * A readonly target without a constructor: it cannot be filled from outside.
 */
final class FrozenReference
{
    public readonly string $reference;
}
