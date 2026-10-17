<?php

// Copy of the E3-P3 golden (builder commit 6ea3fe0cd), see tests/Smoke/SmokeTest.php. Do not edit by hand.
// Only the body of __invoke() differs: it is the dev area of the node, filled for the smoke (stub: throws).
declare(strict_types=1);

namespace Ecommerce\Sales\Process\OrderBulkQuote\Command\Handler\Action;

use Ecommerce\Sales\Process\OrderBulkQuote\Command\Handler\OrderBulkQuoteContext;
use Ecommerce\Sales\Process\OrderBulkQuote\Command\OrderBulkQuoteLinesItem;
use JardisSupport\Contract\Kernel\DomainResponseInterface;
use JardisSupport\Workflow\Data\NodeResult;

/**
 * Node PriceBulkQuote of the process OrderBulkQuote (action).
 *
 * Bepreist die Angebotspositionen. Der Koerper ist Dev-Code; der
 * Eingang ist vor dem ersten Knoten vollstaendig geprueft.
 */
final class PriceBulkQuote
{
    /**
     * @param \Closure(class-string, object): DomainResponseInterface $call The kernel seam of the handler
     */
    public function __construct(private readonly \Closure $call)
    {
    }

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
