<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Handler\Failure;

use JardisSupport\Workflow\Data\Failure\Conflict;

/**
 * Builds the 409 failure form: why the write collided, on which aggregate, which constraints.
 */
final class ConcurrencyConflictPayload
{
    /**
     * @param array<string, mixed> $context
     * @param list<string> $violated
     */
    public function __invoke(
        string $messageKey,
        string $reason,
        string $aggregate,
        array $context,
        array $violated
    ): Conflict {
        return new Conflict($messageKey, $context, $reason, $aggregate, $violated);
    }
}
