<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Handler\Start;

use JardisSupport\Contract\Kernel\ResponseStatus;
use JardisSupport\Workflow\Data\ProcessOutcome;
use JardisSupport\Workflow\Data\RunRequest;
use JardisSupport\Workflow\Handler\Failure\BuildFailureOutcome;

/**
 * Run layer: a process that declares an actor class needs an actor.
 *
 * Without one the run ends with a 401 before any node or transaction. Whether the actor may do
 * the thing is a decision of the nodes (403), never of the start.
 */
final class CheckActor
{
    public const MESSAGE_KEY = 'process.actorMissing';

    public function __construct(private readonly BuildFailureOutcome $buildFailureOutcome = new BuildFailureOutcome())
    {
    }

    /**
     * @param \Closure(RunRequest): ProcessOutcome $next
     */
    public function __invoke(RunRequest $request, \Closure $next): ProcessOutcome
    {
        if ($request->definition->getActorClass() !== null && $request->actor === null) {
            return ($this->buildFailureOutcome)(ResponseStatus::Unauthorized, self::MESSAGE_KEY);
        }

        return $next($request);
    }
}
