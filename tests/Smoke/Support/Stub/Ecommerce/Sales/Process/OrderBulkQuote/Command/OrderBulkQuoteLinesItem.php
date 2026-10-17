<?php

declare(strict_types=1);

namespace Ecommerce\Sales\Process\OrderBulkQuote\Command;

/**
 * Stub of the generated list-item class of the input field `lines`.
 */
readonly class OrderBulkQuoteLinesItem
{
    /**
     * @param list<string>|null $serialNumbers
     */
    public function __construct(
        public ?OrderBulkQuoteLinesDiscountItem $discount,
        public int $quantity,
        public ?array $serialNumbers,
        public string $sku,
        public string $unitPrice
    ) {
    }
}
