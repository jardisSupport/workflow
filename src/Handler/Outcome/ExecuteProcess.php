<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Handler\Outcome;

use JardisSupport\Workflow\Data\NodeResult;
use JardisSupport\Workflow\Data\ProcessContext;
use JardisSupport\Workflow\Data\ProcessDefinition;
use JardisSupport\Workflow\Data\ProcessOutcome;
use JardisSupport\Workflow\Data\RunRequest;
use JardisSupport\Workflow\Handler\Context\CreateContext;
use JardisSupport\Workflow\Handler\Node\ResolveNode;
use JardisSupport\Workflow\Handler\Routing\Workflow;

/**
 * The core of a run: start context, then the engine over the node chain, then the outcome.
 *
 * Runs inside the cross-cutting layers the runner puts around it (input, actor, transaction).
 */
final class ExecuteProcess
{
    /**
     * @param \Closure(ProcessDefinition, string, ProcessContext, \Closure): NodeResult $chain
     */
    public function __construct(
        private readonly CreateContext $createContext,
        private readonly Workflow $engine,
        private readonly \Closure $chain,
        private readonly BuildOutcome $buildOutcome,
        private readonly ResolveNode $resolveNode,
    ) {
    }

    public function __invoke(RunRequest $request): ProcessOutcome
    {
        $step = function (string $node, ProcessContext $context) use ($request): NodeResult {
            /** @var class-string $node */
            return ($this->chain)(
                $request->definition,
                $node,
                $context,
                fn(ProcessContext $current): NodeResult => ($this->resolveNode)(
                    ($request->nodeFactory)($node)
                )($current)
            );
        };

        return ($this->buildOutcome)(
            ($this->engine)($request->definition->config(), $step, ($this->createContext)($request))
        );
    }
}
