<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Data\Failure;

use JardisSupport\Contract\Kernel\ResponseStatus;

/**
 * 404 failure form: the addressed aggregate does not exist.
 */
final readonly class NotFound implements FailureForm
{
    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        private string $messageKey,
        private array $context = [],
    ) {
    }

    public function status(): ResponseStatus
    {
        return ResponseStatus::NotFound;
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
}
