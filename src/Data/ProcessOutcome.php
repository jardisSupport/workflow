<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Data;

use JardisSupport\Contract\Kernel\ResponseStatus;

/**
 * Final answer of a process run: status, typed response object, errors and collected events.
 * Serialisation happens elsewhere, in the door in front of the runner.
 *
 * The engine answers a success (200) or the reject of a node (4xx). A reject carries the message key
 * its node named (`messageKey`) and the plain data of the reject (`context`); the failure form of the
 * wire is built from them by the door, not here.
 */
final readonly class ProcessOutcome
{
    /**
     * @param list<string> $errors
     * @param list<object> $events
     * @param array<string, mixed> $context
     */
    public function __construct(
        private ResponseStatus $status,
        private object $response,
        private array $errors = [],
        private array $events = [],
        private ?string $messageKey = null,
        private array $context = [],
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

    /**
     * The message key of a reject (the one the node declared for the status, else the one the reject
     * carries); null for a success.
     */
    public function messageKey(): ?string
    {
        return $this->messageKey;
    }

    /**
     * The plain data of a reject (a 400 below a declared input property carries its prefixed field paths);
     * empty for a success.
     *
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return $this->context;
    }
}
