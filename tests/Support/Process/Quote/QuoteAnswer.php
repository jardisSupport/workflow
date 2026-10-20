<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Tests\Support\Process\Quote;

/**
 * Typed answer of a quote process: two required fields and one optional.
 */
final readonly class QuoteAnswer
{
    public function __construct(public string $quoteNumber, public string $status, public string $note = 'none')
    {
    }
}
