<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Tests\Support\Process\RetryProbeUntilHealthy\Action;

use JardisSupport\Contract\Kernel\DomainResponseInterface;
use JardisSupport\Workflow\Tests\Support\FakeDomainResponse;

/**
 * Fake callee: reports the probe healthy from the given evaluation on.
 */
final class ReadProbeHealth
{
    public function __construct(private readonly int $healthyFrom)
    {
    }

    public function __invoke(int $evaluation): DomainResponseInterface
    {
        return new FakeDomainResponse(200, ['evaluation' => $evaluation, 'healthy' => $evaluation >= $this->healthyFrom]);
    }
}
