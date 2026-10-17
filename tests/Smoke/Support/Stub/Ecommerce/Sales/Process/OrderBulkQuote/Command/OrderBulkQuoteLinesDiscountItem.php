<?php

declare(strict_types=1);

namespace Ecommerce\Sales\Process\OrderBulkQuote\Command;

/**
 * Stub of the generated item class of the input field `lines[].discount`.
 */
readonly class OrderBulkQuoteLinesDiscountItem
{
    public function __construct(
        public string $percent,
        public ?string $reason
    ) {
    }
}
