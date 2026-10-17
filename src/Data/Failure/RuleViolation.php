<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Data\Failure;

use JardisSupport\Contract\Kernel\ResponseStatus;

/**
 * 422 failure form: a business rule rejected the request; names the rule.
 */
final readonly class RuleViolation implements FailureForm
{
    /**
     * @param string $rule name of the violated rule
     * @param array<string, mixed> $context
     */
    public function __construct(
        private string $rule,
        private string $messageKey,
        private array $context = [],
    ) {
    }

    public function status(): ResponseStatus
    {
        return ResponseStatus::RuleViolation;
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

    public function rule(): string
    {
        return $this->rule;
    }
}
