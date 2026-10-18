<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Handler\Outcome;

use JardisSupport\Contract\Kernel\ResponseStatus;
use JardisSupport\Workflow\Data\NodeResult;
use JardisSupport\Workflow\Data\ProcessContext;
use JardisSupport\Workflow\Data\ProcessDefinition;
use JardisSupport\Workflow\Data\ProcessOutcome;
use JardisSupport\Workflow\Exception\ConstructionException;
use JardisSupport\Workflow\Handler\Failure\BuildFailureOutcome;

/**
 * Builds the final {@see ProcessOutcome} from the finished run.
 *
 * Success: status 200, the data of the last step as response object (a last step without data
 * answers an empty object, nothing is handed on from earlier steps), the events of all steps in
 * execution order. Reject: the failure answer of the rejecting step ({@see BuildFailureOutcome}),
 * no events; its message key is the one the node declared for that status
 * ({@see ProcessDefinition::rejects()}) or, when it declared none, the one the reject carries.
 * A 400 below a declared input property ({@see ProcessDefinition::input()}) answers its field
 * paths below that property.
 */
final class BuildOutcome
{
    public function __construct(private readonly BuildFailureOutcome $buildFailureOutcome = new BuildFailureOutcome())
    {
    }

    /**
     * @throws ConstructionException on a run without steps or data that is neither array nor object
     */
    public function __invoke(ProcessContext $context, ?ProcessDefinition $definition = null): ProcessOutcome
    {
        $last = $context->previous() ?? throw new ConstructionException('A finished run has at least one step');
        $status = $last->status();
        if ($last->isRejected() && $status !== null) {
            $node = $this->lastNode($context);

            return ($this->buildFailureOutcome)(
                $status,
                $this->declaredKey($definition, $node, $status) ?? (string) $last->messageKey(),
                $this->prefixed($status, $last->context(), $definition?->getInput($node)),
                $last->errors(),
                null,
                true
            );
        }

        return new ProcessOutcome(ResponseStatus::Success, $this->response($last), [], $this->events($context));
    }

    /**
     * The message key the node declared for the status; null when the node declared none.
     */
    private function declaredKey(?ProcessDefinition $definition, string $node, ResponseStatus $status): ?string
    {
        foreach ($definition?->getRejects($node) ?? [] as $reject) {
            if ($reject['status'] === $status) {
                return $reject['messageKey'];
            }
        }

        return null;
    }

    private function lastNode(ProcessContext $context): string
    {
        $last = array_slice($context->chain(), -1);

        return $last === [] ? '' : $last[0]['node'];
    }

    /**
     * The 400 findings below the input property that fed the node; every other reject unchanged.
     *
     * @param array<string, mixed> $rejectContext
     * @return array<string, mixed>
     */
    private function prefixed(ResponseStatus $status, array $rejectContext, ?string $input): array
    {
        $fields = $rejectContext['fields'] ?? null;
        if ($status !== ResponseStatus::ValidationError || $input === null || !is_array($fields)) {
            return $rejectContext;
        }
        $rejectContext['fields'] = array_map(
            static fn(mixed $finding): mixed => is_array($finding) && is_string($finding['field'] ?? null)
                ? ['field' => $input . '.' . $finding['field']] + $finding
                : $finding,
            $fields
        );

        return $rejectContext;
    }

    private function response(NodeResult $last): object
    {
        $data = $last->data() ?? [];
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
