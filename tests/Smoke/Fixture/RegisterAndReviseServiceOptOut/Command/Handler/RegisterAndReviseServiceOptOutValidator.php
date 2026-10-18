<?php

// Copy of the generat (builder commit df33f2953, tests/Builder/Generated), see tests/Smoke/SmokeTest.php. Do not edit by hand.
declare(strict_types=1);

namespace Ops\Registry\Process\RegisterAndReviseServiceOptOut\Command\Handler;

use JardisSupport\Validation\ValidatorRegistry;
use Ops\Registry\Model\ServiceRegistration\Command\ServiceRegistration;
use Ops\Registry\Model\ServiceRegistration\Command\UpdateServiceRegistration;
use Ops\Registry\Model\ServiceRegistration\Command\Validation\ServiceRegistrationValidator;

/**
 * Input gate of the process RegisterAndReviseServiceOptOut: the validator registry of its input class graph.
 *
 * Hermetic - rules come from the types and value lists of the input fields.
 */
final class RegisterAndReviseServiceOptOutValidator
{
    public function __invoke(): ValidatorRegistry
    {
        $registry = new ValidatorRegistry();
        $registry->register(ServiceRegistration::class, (new ServiceRegistrationValidator())());
        $registry->register(UpdateServiceRegistration::class, (new ServiceRegistrationValidator())());

        return $registry;
    }
}
