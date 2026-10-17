<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Tests\Support\Process\Checkout\Command;

use JardisSupport\Workflow\Data\NodeResult;
use JardisSupport\Workflow\Data\ProcessContext;
use JardisSupport\Workflow\Handler\Node\MapResponse;
use JardisSupport\Workflow\Tests\Support\Process\Checkout\Action\ReserveStock as ReserveStockAction;

/**
 * Fake node: calls the callee and maps its answer.
 */
final class ReserveStock
{
    public function __invoke(ProcessContext $context): NodeResult
    {
        return (new MapResponse())((new ReserveStockAction())());
    }
}
