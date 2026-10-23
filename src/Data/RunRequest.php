<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Data;

/**
 * One call of the runner as a value: what to run, with which input, how nodes are
 * built, and how deep the run is nested.
 *
 * @internal Carries data between the runner and its closures, not part of the public API.
 */
final readonly class RunRequest
{
    /**
     * @param \Closure(class-string): object $nodeFactory
     */
    private function __construct(
        public ProcessDefinition $definition,
        public object $input,
        public \Closure $nodeFactory,
        public int $depth,
    ) {
    }

    /**
     * @param \Closure(class-string): object $nodeFactory
     * @param int $depth nesting depth: 0 for the outer run
     */
    public static function of(
        ProcessDefinition $definition,
        object $input,
        \Closure $nodeFactory,
        int $depth = 0,
    ): self {
        return new self($definition, $input, $nodeFactory, $depth);
    }
}
