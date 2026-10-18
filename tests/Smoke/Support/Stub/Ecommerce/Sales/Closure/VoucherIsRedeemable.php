<?php

declare(strict_types=1);

namespace Ecommerce\Sales\Closure;

/**
 * Stub of the rule VoucherIsRedeemable: the voucher code "expired" is not redeemable.
 */
final class VoucherIsRedeemable
{
    public function __invoke(\stdClass $voucher): \stdClass
    {
        $passed = $voucher->voucherCode !== 'expired';

        return (object) [
            'passed' => $passed,
            'rule' => 'VoucherIsRedeemable',
            'context' => $passed ? [] : ['voucherCode' => $voucher->voucherCode],
        ];
    }
}
