<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Handler\Context;

use JardisSupport\Workflow\Data\ProcessContext;
use JardisSupport\Workflow\Data\RunRequest;
use JardisSupport\Workflow\Exception\ConstructionException;

/**
 * Creates the empty start context of a run: the declared context class, else the base class.
 */
final class CreateContext
{
    /**
     * @throws ConstructionException when the declared context class does not extend {@see ProcessContext}
     */
    public function __invoke(RunRequest $request): ProcessContext
    {
        $class = $request->definition->getContextClass() ?? ProcessContext::class;
        if (!is_a($class, ProcessContext::class, true)) {
            throw new ConstructionException(sprintf(
                'Context class %s of process "%s" must extend %s',
                $class,
                $request->definition->getName(),
                ProcessContext::class
            ));
        }

        return new $class($request->input, $request->depth);
    }
}
