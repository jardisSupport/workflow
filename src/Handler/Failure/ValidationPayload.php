<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Handler\Failure;

use JardisSupport\Workflow\Data\Failure\ValidationFailure;

/**
 * Builds the 400 failure form from the failing fields.
 *
 * Every field finding keeps its structure: `field`, `reason`, `message` and, when the caller
 * names one, a `messageKey` of its own.
 */
final class ValidationPayload
{
    /**
     * @param list<array{field: string, reason: string, message: string, messageKey?: string}> $fields
     * @param array<string, mixed> $context
     */
    public function __invoke(string $messageKey, array $fields, array $context = []): ValidationFailure
    {
        return new ValidationFailure($messageKey, $fields, $context);
    }
}
