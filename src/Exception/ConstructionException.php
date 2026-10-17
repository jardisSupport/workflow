<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Exception;

/**
 * Raised when a definition or a data object is built in an invalid way
 * (unknown route target, mutation after build(), non-4xx reject, ...).
 */
final class ConstructionException extends WorkflowException
{
}
