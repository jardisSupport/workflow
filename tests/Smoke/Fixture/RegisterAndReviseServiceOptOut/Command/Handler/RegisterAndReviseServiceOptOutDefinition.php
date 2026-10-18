<?php

// Copy of the generat (builder commit df33f2953, tests/Builder/Generated), see tests/Smoke/SmokeTest.php. Do not edit by hand.
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
            ->input('registration')
            ->onTrue(ReviseExistingService::class)
            ->rejects(ResponseStatus::ValidationError, 'registry.register_input_rejected')
            ->rejects(ResponseStatus::Conflict, 'registry.register_service_clash')
            ->node(ReviseExistingService::class)
            ->input('revision')
            ->terminal(Route::OnTrue)
            ->rejects(ResponseStatus::NotFound, 'registry.revise_target_missing')
            ->build();
    }
}
