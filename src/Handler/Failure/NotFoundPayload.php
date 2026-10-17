<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Handler\Failure;

use JardisSupport\Workflow\Data\Failure\NotFound;

/**
 * Builds the 404 failure form from the identifying context of the missing thing.
 */
final class NotFoundPayload
{
    /**
     * @param array<string, mixed> $context
     */
    public function __invoke(string $messageKey, array $context): NotFound
    {
        return new NotFound($messageKey, $context);
    }
}
