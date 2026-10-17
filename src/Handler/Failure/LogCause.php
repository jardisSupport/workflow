<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Handler\Failure;

use JardisSupport\Workflow\Data\ProcessOutcome;
use JardisSupport\Workflow\Data\RunRequest;
use Psr\Log\LoggerInterface;

/**
 * Outermost run layer: writes the cause of a failed run to the log, once.
 *
 * A 5xx answer carries nothing of its throwable, the cause lives only in
 * {@see ProcessOutcome::cause()}. This layer hands it to the logger as an `error` (message of the
 * throwable, context `exception` and `process`). Without a logger it does nothing.
 *
 * Exactly once per cause: only the owning run logs. A nested run (sub-process) hands its cause up
 * to the node of the surrounding run, which throws it on, so the owner sees the same throwable and
 * logs it. A 4xx answer is a business outcome and never logged - this includes the 409 of a stale
 * write, which the caller repeats. A logger that fails never replaces the answer.
 */
final class LogCause
{
    public function __construct(private readonly ?LoggerInterface $logger)
    {
    }

    /**
     * @param \Closure(RunRequest): ProcessOutcome $next
     */
    public function __invoke(RunRequest $request, \Closure $next): ProcessOutcome
    {
        $outcome = $next($request);
        $cause = $outcome->cause();

        if ($this->logger === null || $cause === null || $request->parent !== null || $outcome->status()->value < 500) {
            return $outcome;
        }

        try {
            $this->logger->error(
                $cause->getMessage(),
                ['exception' => $cause, 'process' => $request->definition->getName()]
            );
        } catch (\Throwable) {
            // dropped on purpose - the answer of the run does not depend on the log
        }

        return $outcome;
    }
}
