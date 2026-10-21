<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Handler\Context;

use JardisSupport\Workflow\Data\NodeResult;
use JardisSupport\Workflow\Exception\MissingStepException;

/**
 * Reads one field from the data of a step that must carry it: the value, or raises.
 *
 * Decorates {@see ReadStepField}; null (missing or null) is an error. The message reads
 * "Node/field".
 */
final class RequireStepField
{
    public function __construct(private readonly ReadStepField $readStepField = new ReadStepField())
    {
    }

    /**
     * @throws MissingStepException when the field is missing or null
     */
    public function __invoke(NodeResult $result, string $field, string $node): mixed
    {
        return ($this->readStepField)($result, $field)
            ?? throw new MissingStepException("{$node}/{$field}");
    }
}
