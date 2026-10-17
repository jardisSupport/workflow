<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Tests\Support\Process\RetryProbeUntilHealthy\Command;

use JardisSupport\Workflow\Data\NodeResult;
use JardisSupport\Workflow\Data\ProcessContext;
use JardisSupport\Workflow\Data\Route;
use JardisSupport\Workflow\Handler\Node\MapResponse;
use JardisSupport\Workflow\Tests\Support\Process\RetryProbeUntilHealthy\Action\ReadProbeHealth;

/**
 * Fake node: asks the probe and leaves the loop over onExit once it is healthy.
 */
final class EvaluateProbeHealth
{
    public function __construct(private readonly ReadProbeHealth $action)
    {
    }

    public function __invoke(ProcessContext $context): NodeResult
    {
        $result = (new MapResponse())(($this->action)($context->iteration(self::class)));
        $data = $result->data();

        return is_array($data) && $data['healthy'] === true ? NodeResult::route(Route::OnExit, $data) : $result;
    }
}
