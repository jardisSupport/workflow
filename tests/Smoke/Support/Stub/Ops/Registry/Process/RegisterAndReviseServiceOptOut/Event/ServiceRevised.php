<?php

declare(strict_types=1);

namespace Ops\Registry\Process\RegisterAndReviseServiceOptOut\Event;

/**
 * Stub of a process event the smoke node ReviseExistingService emits.
 */
final readonly class ServiceRevised
{
    public function __construct(public string $name)
    {
    }
}
