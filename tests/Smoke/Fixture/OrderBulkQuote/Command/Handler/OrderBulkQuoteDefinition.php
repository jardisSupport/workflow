<?php

// Copy of the E3-P3 golden (builder commit 61b275eec), see tests/Smoke/SmokeTest.php. Do not edit by hand.
declare(strict_types=1);

namespace Ecommerce\Sales\Process\OrderBulkQuote\Command\Handler;

use Ecommerce\Sales\Process\OrderBulkQuote\Command\Handler\Action\PriceBulkQuote;
use Ecommerce\Sales\Process\OrderBulkQuote\Command\OrderBulkQuote;
use Ecommerce\Sales\Process\OrderBulkQuote\Response\PriceBulkQuoteResponse;
use JardisSupport\Workflow\Data\ProcessDefinition;
use JardisSupport\Workflow\Data\Route;

/**
 * Definition of the process OrderBulkQuote: the graph, its routes and the per-node declarations.
 *
 * Hermetic - generated from the process model, rebuilt on every build.
 */
final class OrderBulkQuoteDefinition
{
    public function __invoke(): ProcessDefinition
    {
        return ProcessDefinition::for(OrderBulkQuote::class, 'OrderBulkQuote')
            ->context(OrderBulkQuoteContext::class)
            ->transactional(true)
            ->validate(OrderBulkQuoteValidator::class)
            ->node(PriceBulkQuote::class)
            ->terminal(Route::OnTrue, Route::OnFalse)
            ->response(PriceBulkQuoteResponse::class, 'lineCount', 'discountedLines', 'city', 'voucherCount')
            ->build();
    }
}
