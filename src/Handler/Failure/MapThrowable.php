<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Handler\Failure;

use JardisSupport\Contract\Kernel\ResponseStatus;
use JardisSupport\Contract\Repository\Exception\TransientConflictException;
use JardisSupport\Workflow\Data\ProcessOutcome;
use JardisSupport\Workflow\Data\RunRequest;

/**
 * Outermost run layer: the one table from a thrown failure to the answer of the run.
 *
 * | thrown                                            | answer |
 * |---------------------------------------------------|--------|
 * | TransientConflictException, in the owning run     | 409    |
 * | TransientConflictException, in a nested run       | thrown on - the owner repeats the unit of work |
 * | every other Throwable (construction, configuration, loop limit, unrouted, ...) | 500, empty object |
 *
 * The outcome carries the throwable as its cause; nothing else of it reaches the answer.
 */
final class MapThrowable
{
    public const CONFLICT_KEY = 'process.transientConflict';
    public const CONFLICT_REASON = 'stale_write';

    public function __construct(private readonly BuildFailureOutcome $buildFailureOutcome = new BuildFailureOutcome())
    {
    }

    /**
     * @param \Closure(RunRequest): ProcessOutcome $next
     * @throws TransientConflictException in a nested run
     */
    public function __invoke(RunRequest $request, \Closure $next): ProcessOutcome
    {
        try {
            return $next($request);
        } catch (TransientConflictException $conflict) {
            return $request->parent === null
                ? ($this->buildFailureOutcome)(
                    ResponseStatus::Conflict,
                    self::CONFLICT_KEY,
                    ['reason' => self::CONFLICT_REASON, 'aggregate' => $request->definition->getName()],
                    [],
                    $conflict
                )
                : throw $conflict;
        } catch (\Throwable $failure) {
            return ($this->buildFailureOutcome)(ResponseStatus::InternalError, '', [], [], $failure);
        }
    }
}
