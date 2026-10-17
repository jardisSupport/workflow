<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Tests\Support;

use JardisSupport\Workflow\Data\Failure\FailureForm;
use JardisSupport\Workflow\Data\Failure\NotFound;
use JardisSupport\Workflow\Data\Failure\RuleViolation;
use JardisSupport\Workflow\Data\Failure\ValidationFailure;
use stdClass;

/**
 * Test helper: the `@type` data array of a failure answer, built from the P1 failure DTOs.
 *
 * The envelope forms are the ones fixed by the pre-move generated payload closures
 * (`validation`, `notFound`, `ruleViolation`). The `Conflict` DTO carries no `reason`,
 * `aggregate` or `violated`, so no mapping exists for the 409 form here.
 */
final class FailureData
{
    /**
     * @return array<string, mixed>
     */
    public static function of(FailureForm $failure): array
    {
        return match (true) {
            $failure instanceof ValidationFailure => [
                '@type' => 'validation',
                'fields' => $failure->fields(),
            ],
            $failure instanceof NotFound => [
                '@type' => 'notFound',
                'context' => $failure->context() ?: new stdClass(),
            ],
            $failure instanceof RuleViolation => [
                '@type' => 'ruleViolation',
                'rule' => $failure->rule(),
                'messageKey' => $failure->messageKey(),
                'context' => $failure->context() ?: new stdClass(),
            ],
            default => throw new \InvalidArgumentException(
                sprintf('No data mapping for %s', $failure::class)
            ),
        };
    }
}
