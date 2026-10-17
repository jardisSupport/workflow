<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Handler\Loop;

use JardisSupport\Workflow\Data\NodeResult;
use JardisSupport\Workflow\Data\ProcessContext;
use JardisSupport\Workflow\Data\ProcessDefinition;
use JardisSupport\Workflow\Exception\LoopLimitExceededException;

/**
 * Node decorator: stops a run that has already executed as many steps as the definition allows.
 *
 * The check runs before the node is built, so the node of the exceeding step never executes.
 */
final class GuardLoop
{
    /**
     * @param \Closure(ProcessContext): NodeResult $next
     * @throws LoopLimitExceededException
     */
    public function __invoke(
        ProcessDefinition $definition,
        string $node,
        ProcessContext $context,
        \Closure $next
    ): NodeResult {
        if (count($context->chain()) >= $definition->getLoopLimit()) {
            throw new LoopLimitExceededException($definition->getLoopLimit());
        }

        return $next($context);
    }
}
