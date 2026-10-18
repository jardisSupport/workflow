<?php

// Copy of the generat (builder commit df33f2953, tests/Builder/Generated), see tests/Smoke/SmokeTest.php. Do not edit by hand.
declare(strict_types=1);

namespace Ecommerce\Sales\Process\VoucherRedeemCheck\Command\Handler;

use JardisSupport\Validation\ValidatorRegistry;

/**
 * Input gate of the process VoucherRedeemCheck: the validator registry of its input class graph.
 *
 * Hermetic - rules come from the types and value lists of the input fields.
 */
final class VoucherRedeemCheckValidator
{
    public function __invoke(): ValidatorRegistry
    {
        return new ValidatorRegistry();
    }
}
