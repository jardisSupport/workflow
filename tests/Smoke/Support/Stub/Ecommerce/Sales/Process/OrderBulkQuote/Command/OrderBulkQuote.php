<?php

declare(strict_types=1);

namespace Ecommerce\Sales\Process\OrderBulkQuote\Command;

/**
 * Stub of the generated input class (builder: Command/OrderBulkQuote.php), shape as in the old Generated tree.
 */
readonly class OrderBulkQuote
{
    /**
     * @param list<string>|null $deliveryChannels
     * @param list<OrderBulkQuoteLinesItem> $lines
     * @param list<string> $voucherCodes
     */
    public function __construct(
        public ?array $deliveryChannels,
        public array $lines,
        public string $requestedBy,
        public OrderBulkQuoteShippingAddressItem $shippingAddress,
        public array $voucherCodes
    ) {
    }
}
