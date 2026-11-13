<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Exception;

/**
 * Raised when the answer of ANOTHER system is a technical failure, never a refusal of the node: 401, 403, 405,
 * a 5xx, or a status the contract does not know ({@see \JardisSupport\Workflow\Handler\Node\MapExternalResponse}).
 *
 * It does NOT carry the marker `ReportedFailureExceptionInterface`: nobody on this side has reported the cause, so
 * the door answers 500 and logs it - the status and the reason are in the message.
 */
final class ExternalFailureException extends \RuntimeException
{
    public function __construct(private readonly int $status, string $reason = '', ?\Throwable $previous = null)
    {
        parent::__construct(
            sprintf('External operation answered with status %d%s', $status, $reason === '' ? '' : ': ' . $reason),
            0,
            $previous
        );
    }

    /** The status the other system answered with. */
    public function getStatus(): int
    {
        return $this->status;
    }
}
