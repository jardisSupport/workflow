<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Tests\Support\Process\RetryProbeUntilHealthy\Command;

use JardisSupport\Workflow\Data\NodeResult;
use JardisSupport\Workflow\Data\ProcessContext;

/**
 * Fake node: the wait itself is not modelled, it only passes on.
 */
final class WaitBeforeRetry
{
    public function __invoke(ProcessContext $context): NodeResult
    {
        return NodeResult::ok(['waited' => $context->iteration(self::class) + 1]);
    }
}
