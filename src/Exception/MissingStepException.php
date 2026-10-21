<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Exception;

/**
 * Raised when a node reads a predecessor step (or a field of it) that must exist but does not.
 *
 * A programming error of the process wiring, not a business outcome: the message names the
 * missing step ("Node") or the missing field of a step ("Node/field"). The door maps it like any
 * other throwable.
 */
final class MissingStepException extends WorkflowException
{
}
