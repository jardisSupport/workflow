<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Tests\Support\Into;

/**
 * Typed reference with a constructor: one required field, one optional.
 */
final readonly class BuiltReference
{
    public function __construct(public string $reference, public int $count = 1)
    {
    }
}
