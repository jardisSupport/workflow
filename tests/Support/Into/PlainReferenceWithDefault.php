<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Tests\Support\Into;

/**
 * Typed reference without a constructor: one property without, one with a default.
 */
final class PlainReferenceWithDefault
{
    public string $reference;
    public string $note = 'none';
}
