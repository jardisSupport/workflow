<?php

declare(strict_types=1);

namespace Ecommerce\Sales\Process\OrderBulkQuote\Command;

/**
 * Stub of the generated item class of the input field `shippingAddress`.
 */
readonly class OrderBulkQuoteShippingAddressItem
{
    public function __construct(
        public string $city,
        public ?string $postalCode,
        public string $street
    ) {
    }
}
