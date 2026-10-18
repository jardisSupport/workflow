<?php

// Copy of the generat (builder commit df33f2953, tests/Builder/Generated), see tests/Smoke/SmokeTest.php. Do not edit by hand.
declare(strict_types=1);

namespace Ecommerce\Sales\Process\VoucherRedeemCheck\Command;

/**
 * Process DTO for VoucherRedeemCheck.
 *
 * Readonly DTO for command/query operations.
 */
readonly class VoucherRedeemCheck
{
    public function __construct(
        public string $voucherCode
    ) {
    }
}
