<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Tests\Support\Into;

/**
 * Typed reference without a constructor: two public properties without a default.
 */
final class PlainReference
{
    public string $reference;
    public int $count;
}
