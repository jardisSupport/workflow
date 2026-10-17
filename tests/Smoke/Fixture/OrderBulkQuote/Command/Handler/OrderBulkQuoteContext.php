<?php

// Copy of the E3-P3 golden (builder commit 61b275eec), see tests/Smoke/SmokeTest.php. Do not edit by hand.
declare(strict_types=1);

namespace Ecommerce\Sales\Process\OrderBulkQuote\Command\Handler;

use Ecommerce\Sales\Process\OrderBulkQuote\Command\Handler\Action\PriceBulkQuote;
use Ecommerce\Sales\Process\OrderBulkQuote\Command\OrderBulkQuote;
use Ecommerce\Sales\Process\OrderBulkQuote\Response\PriceBulkQuoteResponse;
use JardisSupport\Workflow\Data\ProcessContext;

/**
 * Read view of a run of the process OrderBulkQuote: its input and one typed getter per node.
 *
 * Hermetic - the getters follow the nodes of the process model.
 */
final class OrderBulkQuoteContext extends ProcessContext
{
    public function input(): OrderBulkQuote
    {
        /** @var OrderBulkQuote $input */
        $input = parent::input();

        return $input;
    }

    public function priceBulkQuote(): ?PriceBulkQuoteResponse
    {
        $data = $this->latest(PriceBulkQuote::class)?->data();

        return $data instanceof PriceBulkQuoteResponse ? $data : null;
    }
}
