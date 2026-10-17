<?php

// Copy of the E3-P3 golden (builder commit 61b275eec), see tests/Smoke/SmokeTest.php. Do not edit by hand.
declare(strict_types=1);

namespace Ops\Registry\Process\RegisterAndReviseServiceOptOut\Command\Handler;

use JardisSupport\Workflow\Data\NodeResult;
use JardisSupport\Workflow\Data\ProcessContext;
use Ops\Registry\Process\RegisterAndReviseServiceOptOut\Command\Handler\Action\RegisterNewService;
use Ops\Registry\Process\RegisterAndReviseServiceOptOut\Command\Handler\Action\ReviseExistingService;
use Ops\Registry\Process\RegisterAndReviseServiceOptOut\Command\RegisterAndReviseServiceOptOut;

/**
 * Read view of a run of the process RegisterAndReviseServiceOptOut: its input and one typed getter per node.
 *
 * Hermetic - the getters follow the nodes of the process model.
 */
final class RegisterAndReviseServiceOptOutContext extends ProcessContext
{
    public function input(): RegisterAndReviseServiceOptOut
    {
        /** @var RegisterAndReviseServiceOptOut $input */
        $input = parent::input();

        return $input;
    }

    public function registerNewService(): ?NodeResult
    {
        return $this->latest(RegisterNewService::class);
    }

    public function reviseExistingService(): ?NodeResult
    {
        return $this->latest(ReviseExistingService::class);
    }
}
