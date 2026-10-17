<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Data\Failure;

use JardisSupport\Contract\Kernel\ResponseStatus;

/**
 * 400 failure form: the input is malformed; carries the failing fields.
 */
final readonly class ValidationFailure implements FailureForm
{
    /**
     * @param list<array{field: string, reason: string, message: string}> $fields
     * @param array<string, mixed> $context
     */
    public function __construct(
        private string $messageKey,
        private array $fields,
        private array $context = [],
    ) {
    }

    public function status(): ResponseStatus
    {
        return ResponseStatus::ValidationError;
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

    /**
     * @return list<array{field: string, reason: string, message: string}>
     */
    public function fields(): array
    {
        return $this->fields;
    }
}
