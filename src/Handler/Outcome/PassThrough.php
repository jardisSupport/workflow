<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Handler\Outcome;

use JardisSupport\Workflow\Data\NodeResult;
use JardisSupport\Workflow\Data\ProcessContext;
use JardisSupport\Workflow\Data\ProcessDefinition;

/**
 * Node decorator: a routed result without data hands on the data of the preceding step.
 *
 * Nodes that only emit events or decide a route thus never swallow the answer of the node
 * before them. Without a preceding answer the data is an empty array. A reject is left alone.
 */
final class PassThrough
{
    /**
     * @param \Closure(ProcessContext): NodeResult $next
     */
    public function __invoke(
        ProcessDefinition $definition,
        string $node,
        ProcessContext $context,
        \Closure $next
    ): NodeResult {
        $result = $next($context);
        $route = $result->takenRoute();
        if ($route === null || $result->data() !== null) {
            return $result;
        }

        return NodeResult::route($route, $context->previous()?->data() ?? [])->withEvents(...$result->events());
    }
}
