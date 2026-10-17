<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Handler\Routing;

use JardisSupport\Workflow\Data\NodeResult;
use JardisSupport\Workflow\Data\ProcessContext;
use JardisSupport\Workflow\Data\WorkflowConfig;
use JardisSupport\Workflow\Exception\UnroutedStatusException;

/**
 * Walks a routing graph node by node and returns the context extended by every executed step.
 *
 * The engine knows no node logic: for each node it calls the given step exactly once,
 * appends the result to a new context and follows the route the result took. A rejected
 * result ends the run. Routing is always strict: a route without a transition key raises
 * {@see UnroutedStatusException}; a key mapped to null is a declared terminal end.
 */
final class Workflow
{
    /**
     * @param \Closure(class-string, ProcessContext): NodeResult $step executes one node
     * @throws UnroutedStatusException
     */
    public function __invoke(WorkflowConfig $config, \Closure $step, ProcessContext $context): ProcessContext
    {
        $node = $config->entry();

        while ($node !== null) {
            /** @var class-string $node */
            $result = $step($node, $context);
            $context = $context->withStep($node, $result);
            $node = $this->next($config, $node, $result);
        }

        return $context;
    }

    private function next(WorkflowConfig $config, string $node, NodeResult $result): ?string
    {
        $route = $result->takenRoute();
        if ($route === null) {
            return null;
        }

        $transitions = $config->transitions($node);
        if (!array_key_exists($route->value, $transitions)) {
            throw new UnroutedStatusException($node, $route->value);
        }

        return $transitions[$route->value];
    }
}
