<?php

// Copy of the generat (builder commit df33f2953, tests/Builder/Generated), see tests/Smoke/SmokeTest.php. Do not edit by hand.
declare(strict_types=1);

namespace Ecommerce\Sales\Process\VoucherRedeemCheck\Command\Handler\Action;

use Ecommerce\EcommerceContext;
use Ecommerce\Sales\Closure\VoucherIsRedeemable;
use Ecommerce\Sales\Model\Voucher\Repository\LoadVoucherForRule;
use Ecommerce\Sales\Process\VoucherRedeemCheck\Command\Handler\VoucherRedeemCheckContext;
use JardisSupport\Contract\Kernel\ResponseStatus;
use JardisSupport\Workflow\Data\NodeResult;
use JardisSupport\Workflow\Data\Route;
use Throwable;

/**
 * Node CheckVoucherRedeemable of the process VoucherRedeemCheck (rule).
 *
 * Rule-Knoten: prueft VoucherIsRedeemable (aggregate/Voucher) gegen die per
 * voucherCode im Mandanten tenantKey adressierte Wurzel. Unbekannte Wurzel
 * (auch: Wurzel eines anderen Mandanten) -> ON_FAIL notFound.
 *
 * Evaluates the rules VoucherIsRedeemable.
 */
final class CheckVoucherRedeemable extends EcommerceContext
{
    /**
     * @node-id n1
     * @throws Throwable
     */
    public function __invoke(VoucherRedeemCheckContext $ctx): NodeResult
    {
        $identifier = $ctx->input()->voucherCode;
        $loaded = $this->handle(LoadVoucherForRule::class)($ctx->actor()->tenantKey, $identifier);

        if ($loaded === null) {
            return NodeResult::reject(
                ResponseStatus::RuleViolation,
                'sales.voucher.notFound',
                ['identifier' => $identifier]
            );
        }

        $result = $this->handle(VoucherIsRedeemable::class)($loaded);
        if (!$result->passed) {
            return NodeResult::reject(
                ResponseStatus::RuleViolation,
                'sales.voucherNotRedeemable',
                ['rule' => $result->rule] + $result->context
            );
        }

        return NodeResult::route(Route::OnTrue);
    }
}
