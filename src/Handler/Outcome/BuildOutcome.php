<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Handler\Outcome;

use JardisSupport\Contract\Kernel\ResponseStatus;
use JardisSupport\Workflow\Data\NodeResult;
use JardisSupport\Workflow\Data\ProcessContext;
use JardisSupport\Workflow\Data\ProcessOutcome;
use JardisSupport\Workflow\Exception\ConstructionException;
use JardisSupport\Workflow\Handler\Failure\BuildFailureOutcome;

/**
 * Builds the final {@see ProcessOutcome} from the finished run.
 *
 * Success: status 200, the data of the last step as response object, the events of all steps in
 * execution order. Reject: the failure answer of the rejecting step ({@see BuildFailureOutcome}),
 * no events.
 */
final class BuildOutcome
{
    public function __construct(private readonly BuildFailureOutcome $buildFailureOutcome = new BuildFailureOutcome())
    {
    }

    /**
     * @throws ConstructionException on a run without steps or data that is neither array nor object
     */
    public function __invoke(ProcessContext $context): ProcessOutcome
    {
        $last = $context->previous() ?? throw new ConstructionException('A finished run has at least one step');
        $status = $last->status();
        if ($last->isRejected() && $status !== null) {
            return ($this->buildFailureOutcome)(
                $status,
                (string) $last->messageKey(),
                $last->context(),
                $last->errors()
            );
        }

        return new ProcessOutcome(ResponseStatus::Success, $this->response($last), [], $this->events($context));
    }

    private function response(NodeResult $last): object
    {
        $data = $last->data();
        if (!is_array($data) && !is_object($data)) {
            throw new ConstructionException('The answer of a run must be an array or an object');
        }

        return (object) $data;
    }

    /**
     * @return list<object>
     */
    private function events(ProcessContext $context): array
    {
        $events = [];
        foreach ($context->chain() as $step) {
            array_push($events, ...$step['result']->events());
        }

        return $events;
    }
}
