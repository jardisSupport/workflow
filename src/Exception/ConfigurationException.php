<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Exception;

/**
 * Raised when the runtime wiring around a valid definition is unusable
 * (e.g. a required kernel accessor is missing).
 */
final class ConfigurationException extends WorkflowException
{
}
