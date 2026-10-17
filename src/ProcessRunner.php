<?php

declare(strict_types=1);

namespace JardisSupport\Workflow;

use JardisSupport\Contract\DbConnection\DbConnectionInterface;
use JardisSupport\Contract\Kernel\DomainKernelInterface;
use JardisSupport\Contract\Validation\ValidatorInterface;
use JardisSupport\Workflow\Data\NodeResult;
use JardisSupport\Workflow\Data\ProcessContext;
use JardisSupport\Workflow\Data\ProcessDefinition;
use JardisSupport\Workflow\Data\ProcessOutcome;
use JardisSupport\Workflow\Data\RunRequest;
use JardisSupport\Workflow\Handler\Context\CreateContext;
use JardisSupport\Workflow\Handler\Context\HydrateResponse;
use JardisSupport\Workflow\Handler\Events\CollectEvents;
use JardisSupport\Workflow\Handler\Failure\BuildFailureForm;
use JardisSupport\Workflow\Handler\Failure\BuildFailureOutcome;
use JardisSupport\Workflow\Handler\Failure\MapThrowable;
use JardisSupport\Workflow\Handler\Loop\GuardLoop;
use JardisSupport\Workflow\Handler\Node\ApplyRuleMode;
use JardisSupport\Workflow\Handler\Node\ResolveNode;
use JardisSupport\Workflow\Handler\Outcome\BuildOutcome;
use JardisSupport\Workflow\Handler\Outcome\ExecuteProcess;
use JardisSupport\Workflow\Handler\Outcome\PassThrough;
use JardisSupport\Workflow\Handler\Routing\Workflow;
use JardisSupport\Workflow\Handler\Start\CheckActor;
use JardisSupport\Workflow\Handler\Start\ResolveConnection;
use JardisSupport\Workflow\Handler\Start\ValidateInput;
use JardisSupport\Workflow\Handler\Transaction\Bracket;

/**
 * Runs a process definition and answers with a {@see ProcessOutcome}.
 *
 * Pure composition, no logic of its own. Each node is wrapped, outside in, by GuardLoop,
 * CollectEvents, HydrateResponse, PassThrough and ApplyRuleMode; the engine walks the graph over
 * that chain and BuildOutcome turns the finished run into the answer. The run itself sits in
 * layers, outside in: MapThrowable, ValidateInput, CheckActor, Bracket, then the core.
 *
 * The connection is optional: a process that opted out of the transaction runs without one, a
 * transactional process without one ends with 500 (see {@see Bracket}). {@see self::forKernel()}
 * takes the writer from the kernel.
 */
final class ProcessRunner
{
    /** @var \Closure(RunRequest): ProcessOutcome */
    private \Closure $execution;

    public function __construct(?DbConnectionInterface $connection, ValidatorInterface $validator)
    {
        $buildFailureForm = new BuildFailureForm();
        $buildFailureOutcome = new BuildFailureOutcome($buildFailureForm);
        $chain = array_reduce(
            array_reverse([
                new GuardLoop(),
                new CollectEvents(),
                new HydrateResponse(),
                new PassThrough(),
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
        $core = new ExecuteProcess(
            new CreateContext(),
            new Workflow(),
            $chain,
            new BuildOutcome($buildFailureOutcome),
            new ResolveNode()
        );

        $this->execution = array_reduce(
            array_reverse([
                new MapThrowable($buildFailureOutcome),
                new ValidateInput($validator, $buildFailureOutcome),
                new CheckActor($buildFailureOutcome),
                new Bracket($connection),
            ]),
            static fn(\Closure $inner, callable $layer): \Closure => static fn(
                RunRequest $request
            ): ProcessOutcome => $layer($request, $inner),
            $core(...)
        );
    }

    /**
     * The runner of a kernel: the writer connection comes from the kernel's `dbConnection()`
     * (a pool, a raw driver handle or none), see {@see ResolveConnection}.
     */
    public static function forKernel(DomainKernelInterface $kernel, ValidatorInterface $validator): self
    {
        return new self((new ResolveConnection())($kernel), $validator);
    }

    /**
     * @param \Closure(class-string): object $nodeFactory builds the node object; the package checks it is callable
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
