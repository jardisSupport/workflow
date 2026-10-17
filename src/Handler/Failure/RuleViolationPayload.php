<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Handler\Failure;

use JardisSupport\Workflow\Data\Failure\RuleViolation;

/**
 * Builds the 422 failure form from the violated rule and its message key.
 */
final class RuleViolationPayload
{
    /**
     * @param array<string, mixed> $context
     */
    public function __invoke(string $rule, string $messageKey, array $context): RuleViolation
    {
        return new RuleViolation($rule, $messageKey, $context);
    }
}
