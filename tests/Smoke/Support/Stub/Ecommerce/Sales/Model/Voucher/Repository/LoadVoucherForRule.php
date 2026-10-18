<?php

declare(strict_types=1);

namespace Ecommerce\Sales\Model\Voucher\Repository;

/**
 * Stub of the dev-owned loader of the rule VoucherIsRedeemable: the voucher code "unknown" has no root.
 */
final class LoadVoucherForRule
{
    public function __invoke(string $tenantKey, string $identifier): ?\stdClass
    {
        return $identifier === 'unknown' ? null : (object) ['voucherCode' => $identifier, 'tenantKey' => $tenantKey];
    }
}
