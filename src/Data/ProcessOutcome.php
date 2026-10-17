<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Data;

use JardisSupport\Contract\Kernel\ResponseStatus;
use Throwable;

/**
 * Final answer of a process run: status, typed response object, errors, collected events
 * and - for an unexpected failure - the cause. Serialisation happens elsewhere.
 *
 * `declaredMessageKey` is the message key a node named when it rejected the run; the failures of
 * the package's own layers (input, actor, thrown) name none. Only a declared key reaches the
 * wire next to the failure form.
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
        private ?string $declaredMessageKey = null,
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

    /**
     * The message key the rejecting node named; null for a success and for a failure of the package's own layers.
     */
    public function declaredMessageKey(): ?string
    {
        return $this->declaredMessageKey;
    }
}
