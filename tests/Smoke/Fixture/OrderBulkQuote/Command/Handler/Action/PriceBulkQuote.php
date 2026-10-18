<?php

// Copy of the generat (builder commit df33f2953, tests/Builder/Generated), see tests/Smoke/SmokeTest.php. Do not edit by hand.
// Only the body of __invoke() differs: it is the dev area of the node, filled for the smoke (stub: throws).
declare(strict_types=1);

namespace Ecommerce\Sales\Process\OrderBulkQuote\Command\Handler\Action;

use Ecommerce\EcommerceContext;
use Ecommerce\Sales\Process\OrderBulkQuote\Command\Handler\OrderBulkQuoteContext;
use Ecommerce\Sales\Process\OrderBulkQuote\Command\OrderBulkQuoteLinesItem;
use JardisSupport\Workflow\Data\NodeResult;

/**
 * Node PriceBulkQuote of the process OrderBulkQuote (action).
 *
 * Bepreist die Angebotspositionen. Der Koerper ist Dev-Code; der
 * Eingang ist vor dem ersten Knoten vollstaendig geprueft.
 */
final class PriceBulkQuote extends EcommerceContext
{
    /**
     * @node-id q1w2e3r4
     */
    public function __invoke(OrderBulkQuoteContext $ctx): NodeResult
    {
        $input = $ctx->input();
        $discounted = count(array_filter(
            $input->lines,
            static fn(OrderBulkQuoteLinesItem $line): bool => $line->discount !== null
        ));

        return NodeResult::ok([
            'lineCount' => count($input->lines),
            'discountedLines' => $discounted,
            'city' => $input->shippingAddress->city,
            'voucherCount' => count($input->voucherCodes),
        ]);
    }
}
