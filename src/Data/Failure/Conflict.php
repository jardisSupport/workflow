<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Data\Failure;

use JardisSupport\Contract\Kernel\ResponseStatus;

/**
 * 409 failure form: the write collides with the current state.
 */
final readonly class Conflict implements FailureForm
{
    /**
     * @param array<string, mixed> $context
     * @param list<string> $violated names of the violated constraints
     */
    public function __construct(
        private string $messageKey,
        private array $context = [],
        private ?string $reason = null,
        private ?string $aggregate = null,
        private array $violated = [],
    ) {
    }

    public function status(): ResponseStatus
    {
        return ResponseStatus::Conflict;
    }

    public function messageKey(): string
    {
        return $this->messageKey;
    }

    /**
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return $this->context;
    }

    /** Why the write collided (for example "stale"); null when the cause did not name one. */
    public function reason(): ?string
    {
        return $this->reason;
    }

    /** The aggregate the collision happened on; null when not named. */
    public function aggregate(): ?string
    {
        return $this->aggregate;
    }

    /**
     * @return list<string>
     */
    public function violated(): array
    {
        return $this->violated;
    }
}
