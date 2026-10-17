<?php

// Copy of the E3-P3 golden (builder commit 6ea3fe0cd), see tests/Smoke/SmokeTest.php. Do not edit by hand.
declare(strict_types=1);

namespace Ops\Registry\Process\RegisterAndReviseServiceOptOut\Command\Handler;

use JardisSupport\Contract\Kernel\ResponseStatus;
use JardisSupport\Workflow\Data\ProcessDefinition;
use JardisSupport\Workflow\Data\Route;
use Ops\Registry\Process\RegisterAndReviseServiceOptOut\Command\Handler\Action\RegisterNewService;
use Ops\Registry\Process\RegisterAndReviseServiceOptOut\Command\Handler\Action\ReviseExistingService;
use Ops\Registry\Process\RegisterAndReviseServiceOptOut\Command\RegisterAndReviseServiceOptOut;

/**
 * Definition of the process RegisterAndReviseServiceOptOut: the graph, its routes and the per-node declarations.
 *
 * Hermetic - generated from the process model, rebuilt on every build.
 */
final class RegisterAndReviseServiceOptOutDefinition
{
    public function __invoke(): ProcessDefinition
    {
        return ProcessDefinition::for(RegisterAndReviseServiceOptOut::class, 'RegisterAndReviseServiceOptOut')
            ->context(RegisterAndReviseServiceOptOutContext::class)
            ->transactional(false)
            ->validate(RegisterAndReviseServiceOptOutValidator::class)
            ->node(RegisterNewService::class)
            ->onTrue(ReviseExistingService::class)
            ->terminal(Route::OnFalse)
            ->rejects(ResponseStatus::ValidationError, 'registry.register_input_rejected')
            ->rejects(ResponseStatus::Conflict, 'registry.register_service_clash')
            ->node(ReviseExistingService::class)
            ->terminal(Route::OnTrue, Route::OnFalse)
            ->rejects(ResponseStatus::NotFound, 'registry.revise_target_missing')
            ->build();
    }
}
