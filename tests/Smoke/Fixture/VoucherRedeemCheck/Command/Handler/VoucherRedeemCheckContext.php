<?php

// Copy of the generat (builder commit df33f2953, tests/Builder/Generated), see tests/Smoke/SmokeTest.php. Do not edit by hand.
declare(strict_types=1);

namespace Ecommerce\Sales\Process\VoucherRedeemCheck\Command\Handler;

use Ecommerce\Sales\Process\VoucherRedeemCheck\Command\Handler\Action\CheckVoucherRedeemable;
use Ecommerce\Sales\Process\VoucherRedeemCheck\Command\VoucherRedeemCheck;
use JardisSupport\Workflow\Data\NodeResult;
use JardisSupport\Workflow\Data\ProcessContext;

/**
 * Read view of a run of the process VoucherRedeemCheck: its input and one typed getter per node.
 *
 * Hermetic - the getters follow the nodes of the process model.
 */
final class VoucherRedeemCheckContext extends ProcessContext
{
    public function input(): VoucherRedeemCheck
    {
        /** @var VoucherRedeemCheck $input */
        $input = parent::input();

        return $input;
    }

    public function actor(): VoucherRedeemCheckActor
    {
        // The package's start check (CheckActor) answers 401 before a node runs
        // without the declared actor, so a node always has one.
        /** @var VoucherRedeemCheckActor $actor */
        $actor = parent::actor();

        return $actor;
    }

    public function checkVoucherRedeemable(): ?NodeResult
    {
        return $this->latest(CheckVoucherRedeemable::class);
    }
}
