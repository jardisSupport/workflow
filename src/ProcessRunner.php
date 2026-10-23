<?php

declare(strict_types=1);

namespace JardisSupport\Workflow;

use JardisSupport\Workflow\Data\NodeResult;
use JardisSupport\Workflow\Data\ProcessContext;
use JardisSupport\Workflow\Data\ProcessDefinition;
use JardisSupport\Workflow\Data\ProcessOutcome;
use JardisSupport\Workflow\Data\RunRequest;
use JardisSupport\Workflow\Handler\Context\CreateContext;
use JardisSupport\Workflow\Handler\Context\HydrateResponse;
use JardisSupport\Workflow\Handler\Events\CollectEvents;
use JardisSupport\Workflow\Handler\Loop\GuardLoop;
use JardisSupport\Workflow\Handler\Node\ApplyRuleMode;
use JardisSupport\Workflow\Handler\Node\ResolveNode;
use JardisSupport\Workflow\Handler\Outcome\BuildOutcome;
use JardisSupport\Workflow\Handler\Outcome\ExecuteProcess;
use JardisSupport\Workflow\Handler\Routing\Workflow;

/**
 * Runs a process definition and answers with a {@see ProcessOutcome}.
 *
 * The engine and nothing around it: each node is wrapped, outside in, by GuardLoop, CollectEvents,
 * HydrateResponse and ApplyRuleMode; the engine walks the graph over that chain and BuildOutcome
 * turns the finished run into the answer. Nothing is handed on between nodes: the answer is what
 * the last node returned, a later node reads earlier data explicitly through
 * {@see ProcessContext::latest()} / {@see ProcessContext::all()}.
 *
 * The runner checks no input, opens no transaction and logs nothing: that is the
 * business of the door in front of it. What a node throws runs through unchanged; the engine
 * answers a finished run only (200, or a 4xx a node rejected with).
 */
final class ProcessRunner
{
    /**
     * @param \Closure(class-string): object $nodeFactory builds the node object; the package checks it is callable
     * @param int $depth nesting depth of this run: 0 at the outermost level, else the depth of the calling run plus one
     */
    public function run(
        ProcessDefinition $definition,
        object $input,
        \Closure $nodeFactory,
        int $depth = 0
    ): ProcessOutcome {
        return $this->core()(RunRequest::of($definition, $input, $nodeFactory, $depth));
    }

    private function core(): ExecuteProcess
    {
        $chain = array_reduce(
            array_reverse([
                new GuardLoop(),
                new CollectEvents(),
                new HydrateResponse(),
                new ApplyRuleMode(),
            ]),
            static fn(\Closure $inner, callable $decorator): \Closure => static fn(
                ProcessDefinition $definition,
                string $node,
                ProcessContext $context,
                \Closure $call
            ): NodeResult => $decorator(
                $definition,
                $node,
                $context,
                static fn(ProcessContext $current): NodeResult => $inner($definition, $node, $current, $call)
            ),
            static fn(ProcessDefinition $definition, string $node, ProcessContext $context, \Closure $call): NodeResult
                => $call($context)
        );

        return new ExecuteProcess(new CreateContext(), new Workflow(), $chain, new BuildOutcome(), new ResolveNode());
    }
}
