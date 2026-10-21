<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Handler\Context;

use JardisSupport\Workflow\Data\NodeResult;

/**
 * Reads one field from the data of a step: an array key or a public property of an object.
 *
 * A missing field, a null value, or data that is neither array nor object read as null.
 */
final class ReadStepField
{
    public function __invoke(NodeResult $result, string $field): mixed
    {
        $data = $result->data();

        if (is_array($data)) {
            return $data[$field] ?? null;
        }

        if (is_object($data)) {
            return get_object_vars($data)[$field] ?? null;
        }

        return null;
    }
}
