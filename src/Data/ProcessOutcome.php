<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Data;

use JardisSupport\Contract\Kernel\AnswerableOutcomeInterface;
use JardisSupport\Contract\Kernel\ResponseStatus;

/**
 * Final answer of a process run: status, typed response object, errors and collected events.
 * Serialisation happens elsewhere, in the door in front of the runner.
 *
 * The engine answers a success (200) or the reject of a node (4xx). A reject carries the message key
 * its node named (`messageKey`) and the plain data of the reject (`context`); the failure form of the
 * wire is built from them by the door, not here.
 *
 * It is an {@see AnswerableOutcomeInterface} (an `OutcomeInterface` that also carries response, errors, events,
 * message key and context): whoever reacts to how the run ended (the transaction bracket) asks `isFailed()` and
 * compares no status numbers, whoever builds the answer of the door from it (`jardissupport/response`) reads the
 * rest - neither knows this class.
 */
final readonly class ProcessOutcome implements AnswerableOutcomeInterface
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

    /**
     * A copy with another answer; status, errors, events, message key and context stay as they are. The outcome
     * itself is unchanged - whoever reshapes the typed answer (for instance onto its wire form) takes the copy.
     */
    public function withResponse(object $response): self
    {
        return new self($this->status, $response, $this->errors, $this->events, $this->messageKey, $this->context);
    }

    public function status(): ResponseStatus
    {
        return $this->status;
    }

    /**
     * True when the run ended in a reject (status 400 and above); a finished run is a 200.
     */
    public function isFailed(): bool
    {
        return $this->status->value >= 400;
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
