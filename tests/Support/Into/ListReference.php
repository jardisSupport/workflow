<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Tests\Support\Into;

/**
 * Typed reference without a constructor that carries a list field.
 */
final class ListReference
{
    public string $reference;
    /** @var list<array<string, mixed>> */
    public array $items;
}
