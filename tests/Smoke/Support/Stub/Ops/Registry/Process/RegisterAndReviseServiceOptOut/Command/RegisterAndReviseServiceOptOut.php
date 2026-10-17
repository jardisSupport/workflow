<?php

declare(strict_types=1);

namespace Ops\Registry\Process\RegisterAndReviseServiceOptOut\Command;

use Ops\Registry\Model\ServiceRegistration\Command\ServiceRegistration;
use Ops\Registry\Model\ServiceRegistration\Command\UpdateServiceRegistration;

/**
 * Stub of the generated input class of the process RegisterAndReviseServiceOptOut.
 */
readonly class RegisterAndReviseServiceOptOut
{
    public function __construct(
        public ServiceRegistration $registration,
        public UpdateServiceRegistration $revision
    ) {
    }
}
