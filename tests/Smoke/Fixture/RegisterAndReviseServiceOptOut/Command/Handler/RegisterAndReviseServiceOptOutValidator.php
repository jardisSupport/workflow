<?php

// Copy of the E3-P3 golden (builder commit 61b275eec), see tests/Smoke/SmokeTest.php. Do not edit by hand.
declare(strict_types=1);

namespace Ops\Registry\Process\RegisterAndReviseServiceOptOut\Command\Handler;

use JardisSupport\Validation\ValidatorRegistry;

/**
 * Input gate of the process RegisterAndReviseServiceOptOut: the validator registry of its input class graph.
 *
 * Hermetic - rules come from the types and value lists of the input fields.
 */
final class RegisterAndReviseServiceOptOutValidator
{
    public function __invoke(): ValidatorRegistry
    {
        return new ValidatorRegistry();
    }
}
