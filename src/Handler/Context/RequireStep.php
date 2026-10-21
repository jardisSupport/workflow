<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Handler\Context;

use JardisSupport\Workflow\Exception\MissingStepException;

/**
 * Reads a predecessor step that must exist: hands the step back typed, or raises.
 *
 * Works for the raw {@see \JardisSupport\Workflow\Data\NodeResult} and for any typed step object
 * (for example the typed answer getter of a generated context). The message is the node name.
 */
final class RequireStep
{
    /**
     * @template T of object
     * @param T|null $step
     * @return T
     * @throws MissingStepException when the step is null
     */
    public function __invoke(?object $step, string $node): object
    {
        return $step ?? throw new MissingStepException($node);
    }
}
