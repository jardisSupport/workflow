<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Data;

/**
 * One call of the runner as a value: what to run, with which input and actor, how nodes are
 * built, and the surrounding run (if any) it is nested in.
 *
 * @internal Carries data between the runner and its closures, not part of the public API.
 */
final readonly class RunRequest
{
    /**
     * @param \Closure(class-string): (callable(ProcessContext): NodeResult) $nodeFactory
     */
    private function __construct(
        public ProcessDefinition $definition,
        public object $input,
        public ?object $actor,
        public \Closure $nodeFactory,
        public ?ProcessContext $parent,
        public int $depth,
    ) {
    }

    /**
     * The depth is derived: one deeper than the surrounding run, 0 without one.
     *
     * @param \Closure(class-string): (callable(ProcessContext): NodeResult) $nodeFactory
     */
    public static function of(
        ProcessDefinition $definition,
        object $input,
        ?object $actor,
        \Closure $nodeFactory,
        ?ProcessContext $parent = null,
    ): self {
        return new self(
            $definition,
            $input,
            $actor,
            $nodeFactory,
            $parent,
            $parent === null ? 0 : $parent->depth() + 1
        );
    }
}
