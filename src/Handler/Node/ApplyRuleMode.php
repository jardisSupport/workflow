<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Handler\Node;

use JardisSupport\Contract\Kernel\ResponseStatus;
use JardisSupport\Workflow\Data\NodeResult;
use JardisSupport\Workflow\Data\ProcessContext;
use JardisSupport\Workflow\Data\ProcessDefinition;
use JardisSupport\Workflow\Data\Route;
use JardisSupport\Workflow\Data\RuleMode;

/**
 * Node decorator: applies the rule mode the definition declares for the node.
 *
 * A node declared as {@see RuleMode::Decision} turns a rule violation (a 422 reject) into the
 * route {@see Route::OnFalse}, so the process goes on there. A guard - declared or not - keeps
 * the reject and ends the run; every other result passes unchanged.
 */
final class ApplyRuleMode
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
        $isViolation = $result->status() === ResponseStatus::RuleViolation;

        return $isViolation && $definition->getRuleMode($node) === RuleMode::Decision
            ? NodeResult::route(Route::OnFalse)
            : $result;
    }
}
