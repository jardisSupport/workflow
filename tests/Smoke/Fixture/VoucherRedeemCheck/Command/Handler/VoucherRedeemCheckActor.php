<?php

// Copy of the generat (builder commit df33f2953, tests/Builder/Generated), see tests/Smoke/SmokeTest.php. Do not edit by hand.
declare(strict_types=1);

namespace Ecommerce\Sales\Process\VoucherRedeemCheck\Command\Handler;

/**
 * Actor of the process VoucherRedeemCheck: the request attributes the process reads.
 *
 * Hermetic - the properties are the input fields declared with a request attribute.
 */
final readonly class VoucherRedeemCheckActor
{
    public function __construct(
        public string $actorId,
        public string $actorKey,
        public string $sessionRef,
        public string $tenantKey,
    ) {
    }
}
