<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Exception;

use JardisSupport\Contract\Kernel\ReportedFailureExceptionInterface;

/**
 * Raised when a called domain operation answers a 5xx: the inner door has already reported its cause.
 *
 * It carries the marker {@see ReportedFailureExceptionInterface}, so the outer door keeps the 500 but does not
 * log the cause a second time.
 */
final class CalleeFailureException extends \RuntimeException implements ReportedFailureExceptionInterface
{
}
