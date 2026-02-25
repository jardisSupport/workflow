<?php

declare(strict_types=1);

namespace JardisSupport\Workflow;

/**
 * Value Object representing the result of a workflow handler execution.
 *
 * Provides explicit control over workflow transitions by requiring handlers
 * to return a WorkflowResult instead of arbitrary values. This eliminates
 * ambiguity in truthy/falsy evaluation and enables named transitions.
 *
 * Usage:
 *   return new WorkflowResult(WorkflowResult::STATUS_SUCCESS, $data);
 *   return new WorkflowResult(WorkflowResult::STATUS_FAIL, $errors);
 *   return new WorkflowResult(WorkflowResult::ON_RETRY, $data);  // Named transition
 */
final class WorkflowResult
{
    // Status constants
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAIL = 'fail';

    // Standard transition constants
    public const ON_SUCCESS = 'onSuccess';
    public const ON_FAIL = 'onFail';
    public const ON_ERROR = 'onError';
    public const ON_TIMEOUT = 'onTimeout';
    public const ON_RETRY = 'onRetry';
    public const ON_SKIP = 'onSkip';
    public const ON_PENDING = 'onPending';
    public const ON_CANCEL = 'onCancel';

    private readonly ?string $transition;

    /**
     * @param string $status The result status (STATUS_SUCCESS, STATUS_FAIL, or a transition constant)
     * @param mixed $data Optional data payload from the handler
     */
    public function __construct(
        private readonly string $status,
        private readonly mixed $data = null
    ) {
        // Auto-detect explicit transition when using transition constants (not STATUS_*)
        $this->transition = $this->isTransitionConstant($status) ? $status : null;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getData(): mixed
    {
        return $this->data;
    }

    /**
     * Returns the explicit transition name, or null if status-based routing should be used.
     */
    public function getTransition(): ?string
    {
        return $this->transition;
    }

    public function isSuccess(): bool
    {
        return $this->status === self::STATUS_SUCCESS;
    }

    public function isFail(): bool
    {
        return $this->status === self::STATUS_FAIL;
    }

    /**
     * Checks if this result has an explicit named transition.
     */
    public function hasExplicitTransition(): bool
    {
        return $this->transition !== null;
    }

    /**
     * Checks if the status is a transition constant (ON_*) rather than a status constant (STATUS_*).
     */
    private function isTransitionConstant(string $status): bool
    {
        return in_array($status, [
            self::ON_SUCCESS,
            self::ON_FAIL,
            self::ON_ERROR,
            self::ON_TIMEOUT,
            self::ON_RETRY,
            self::ON_SKIP,
            self::ON_PENDING,
            self::ON_CANCEL,
        ], true);
    }
}
