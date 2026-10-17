<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Handler\Node;

use JardisSupport\Workflow\Data\NodeResult;
use JardisSupport\Workflow\Data\ProcessContext;
use JardisSupport\Workflow\Exception\ConstructionException;

/**
 * Turns the object the node factory built into the callable the engine runs.
 *
 * The factory only constructs (`object`); that the object is callable is checked here, in the
 * package: a node that cannot be called is a construction error, named by its class.
 */
final class ResolveNode
{
    /**
     * @return \Closure(ProcessContext): NodeResult
     * @throws ConstructionException when the object is not callable
     */
    public function __invoke(object $node): \Closure
    {
        if (!is_callable($node)) {
            throw new ConstructionException(sprintf(
                'Node %s is not callable: it must implement __invoke(ProcessContext): NodeResult.',
                $node::class
            ));
        }

        return $node(...);
    }
}
