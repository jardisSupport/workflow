<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Exception;

/**
 * Raised when a run executes more node steps than the definition's loop limit allows.
 */
final class LoopLimitExceededException extends WorkflowException
{
    public function __construct(private readonly int $limit)
    {
        parent::__construct(sprintf('Loop limit of %d node steps exceeded', $limit));
    }

    public function getLimit(): int
    {
        return $this->limit;
    }
}
