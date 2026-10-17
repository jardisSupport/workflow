<?php

declare(strict_types=1);

namespace JardisSupport\Workflow;

use JardisSupport\Contract\DbConnection\DbConnectionInterface;
use JardisSupport\Contract\Validation\ValidatorInterface;
use JardisSupport\Workflow\Data\NodeResult;
use JardisSupport\Workflow\Data\ProcessContext;
use JardisSupport\Workflow\Data\ProcessDefinition;
use JardisSupport\Workflow\Data\ProcessOutcome;
use JardisSupport\Workflow\Data\RunRequest;
use JardisSupport\Workflow\Handler\Context\CreateContext;
use JardisSupport\Workflow\Handler\Context\HydrateResponse;
use JardisSupport\Workflow\Handler\Events\CollectEvents;
use JardisSupport\Workflow\Handler\Loop\GuardLoop;
use JardisSupport\Workflow\Handler\Outcome\BuildOutcome;
use JardisSupport\Workflow\Handler\Outcome\ExecuteProcess;
use JardisSupport\Workflow\Handler\Outcome\PassThrough;
use JardisSupport\Workflow\Handler\Routing\Workflow;

/**
 * Runs a process definition and answers with a {@see ProcessOutcome}.
 *
 * Pure composition, no logic of its own: each node is wrapped, outside in, by GuardLoop,
 * CollectEvents, HydrateResponse and PassThrough; the engine walks the graph over that chain
 * and BuildOutcome turns the finished run into the answer.
 */
final class ProcessRunner
{
    /** @var \Closure(RunRequest): ProcessOutcome */
    private \Closure $execution;

    // Both collaborators are used by the start and transaction layers that wrap the core.
    // @phpstan-ignore constructor.unusedParameter, constructor.unusedParameter
    public function __construct(DbConnectionInterface $connection, ValidatorInterface $validator)
    {
        $chain = array_reduce(
            array_reverse([new GuardLoop(), new CollectEvents(), new HydrateResponse(), new PassThrough()]),
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

        $this->execution = (new ExecuteProcess(new CreateContext(), new Workflow(), $chain, new BuildOutcome()))(...);
    }

    /**
     * @param \Closure(class-string): (callable(ProcessContext): NodeResult) $nodeFactory builds the callable of a node
     * @param ProcessContext|null $parent the surrounding run when this one is a sub-process
     */
    public function run(
        ProcessDefinition $definition,
        object $input,
        ?object $actor,
        \Closure $nodeFactory,
        ?ProcessContext $parent = null
    ): ProcessOutcome {
        return ($this->execution)(RunRequest::of($definition, $input, $actor, $nodeFactory, $parent));
    }
}
