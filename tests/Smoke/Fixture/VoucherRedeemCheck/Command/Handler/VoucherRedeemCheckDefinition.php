<?php

// Copy of the generat (builder commit df33f2953, tests/Builder/Generated), see tests/Smoke/SmokeTest.php. Do not edit by hand.
declare(strict_types=1);

namespace Ecommerce\Sales\Process\VoucherRedeemCheck\Command\Handler;

use Ecommerce\Sales\Process\VoucherRedeemCheck\Command\Handler\Action\CheckVoucherRedeemable;
use Ecommerce\Sales\Process\VoucherRedeemCheck\Command\VoucherRedeemCheck;
use JardisSupport\Workflow\Data\ProcessDefinition;
use JardisSupport\Workflow\Data\Route;

/**
 * Definition of the process VoucherRedeemCheck: the graph, its routes and the per-node declarations.
 *
 * Hermetic - generated from the process model, rebuilt on every build.
 */
final class VoucherRedeemCheckDefinition
{
    public function __invoke(): ProcessDefinition
    {
        return ProcessDefinition::for(VoucherRedeemCheck::class, 'VoucherRedeemCheck')
            ->context(VoucherRedeemCheckContext::class)
            ->transactional(true)
            ->validate(VoucherRedeemCheckValidator::class)
            ->actor(VoucherRedeemCheckActor::class)
            ->node(CheckVoucherRedeemable::class)
            ->terminal(Route::OnTrue)
            ->build();
    }
}
