<?php

// Copy of the generat (builder commit df33f2953, tests/Builder/Generated), see tests/Smoke/SmokeTest.php. Do not edit by hand.
declare(strict_types=1);

namespace Ecommerce\Sales\Process\OrderBulkQuote\Command\Handler;

use Ecommerce\Sales\Process\OrderBulkQuote\Command\OrderBulkQuote;
use Ecommerce\Sales\Process\OrderBulkQuote\Command\OrderBulkQuoteLinesDiscountItem;
use Ecommerce\Sales\Process\OrderBulkQuote\Command\OrderBulkQuoteLinesItem;
use JardisSupport\Validation\CompositeFieldValidator;
use JardisSupport\Validation\ValidatorRegistry;
use JardisSupport\Validation\Validator\Contain;
use JardisSupport\Validation\Validator\Format;
use JardisSupport\Validation\Validator\Uuid;

/**
 * Input gate of the process OrderBulkQuote: the validator registry of its input class graph.
 *
 * Hermetic - rules come from the types and value lists of the input fields.
 */
final class OrderBulkQuoteValidator
{
    public function __invoke(): ValidatorRegistry
    {
        $registry = new ValidatorRegistry();
        $validator = new CompositeFieldValidator();
        $validator->field('deliveryChannels')
            ->each(Contain::class, Contain::oneOf(['email', 'post', 'portal']));
        $registry->register(OrderBulkQuote::class, $validator);
        $checkLines = new CompositeFieldValidator();
        $checkLines->field('unitPrice')
            ->validates(Format::class, Format::pattern('/^-?\d+(\.\d+)?$/'));
        $checkLines->field('serialNumbers')
            ->each(Uuid::class);
        $registry->register(OrderBulkQuoteLinesItem::class, $checkLines);
        $checkLinesDiscount = new CompositeFieldValidator();
        $checkLinesDiscount->field('percent')
            ->validates(Format::class, Format::pattern('/^-?\d+(\.\d+)?$/'));
        $registry->register(OrderBulkQuoteLinesDiscountItem::class, $checkLinesDiscount);

        return $registry;
    }
}
