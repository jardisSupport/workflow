<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Handler\Events;

use JardisSupport\Workflow\Data\NodeResult;
use JardisSupport\Workflow\Data\ProcessContext;
use JardisSupport\Workflow\Data\ProcessDefinition;

/**
 * Node decorator: events travel with the node result and are never sent anywhere here.
 *
 * A rejected result carries no events - whatever the node attached is discarded, so a run
 * that ends in a reject reports none.
 */
final class CollectEvents
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

        return $result->isRejected() ? $result->withEvents() : $result;
    }
}
