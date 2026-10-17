<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Exception;

use RuntimeException;

/**
 * Abstract base of every exception the workflow package raises on its own.
 *
 * Callers that only care about "the workflow machinery failed" catch this type;
 * the concrete subclasses name the failure class.
 */
abstract class WorkflowException extends RuntimeException
{
}
