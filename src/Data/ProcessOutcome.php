<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Data;

use JardisSupport\Contract\Kernel\ResponseStatus;
use Throwable;

/**
 * Final answer of a process run: status, typed response object, errors, collected events
 * and - for an unexpected failure - the cause. Serialisation happens elsewhere.
 */
final readonly class ProcessOutcome
{
    /**
     * @param list<string> $errors
     * @param list<object> $events
     */
    public function __construct(
        private ResponseStatus $status,
        private object $response,
        private array $errors = [],
        private array $events = [],
        private ?Throwable $cause = null,
    ) {
    }

    public function status(): ResponseStatus
    {
        return $this->status;
    }

    public function response(): object
    {
        return $this->response;
    }

    /**
     * @return list<string>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * @return list<object>
     */
    public function events(): array
    {
        return $this->events;
    }

    public function cause(): ?Throwable
    {
        return $this->cause;
    }
}
