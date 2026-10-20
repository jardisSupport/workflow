<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Handler\Outcome;

use JardisSupport\Contract\Kernel\ResponseStatus;
use JardisSupport\Workflow\Data\Answer;
use JardisSupport\Workflow\Data\NodeResult;
use JardisSupport\Workflow\Data\ProcessContext;
use JardisSupport\Workflow\Data\ProcessDefinition;
use JardisSupport\Workflow\Data\ProcessOutcome;
use JardisSupport\Workflow\Exception\ConstructionException;
use JardisSupport\Workflow\Handler\Context\ConstructResponse;

/**
 * Builds the final {@see ProcessOutcome} from the finished run.
 *
 * Success: status 200, the response object in the form the process declared ({@see Answer}): the data of
 * the last step (a last step without data answers an empty object, nothing is handed on from earlier steps)
 * or the data of all steps united in run order, projected onto the answer class the process declares
 * ({@see ProcessDefinition::answerResponse()}) when it declares one; the events of all steps in execution order.
 * Reject: only the status, the message key, the context and the errors of the rejecting step, an empty
 * response object, no events; the failure form is the door's business.
 * The message key is the one the node declared for that status ({@see ProcessDefinition::rejects()})
 * or, when it declared none, the one the reject carries. A 400 below a declared input property
 * ({@see ProcessDefinition::input()}) answers its field paths below that property.
 */
final class BuildOutcome
{
    /**
     * @throws ConstructionException on a run without steps, data that is neither array nor object, a list
     *                               as step data of a merged answer, or a declared process answer class that
     *                               does not exist or lacks a required field
     */
    public function __invoke(ProcessContext $context, ?ProcessDefinition $definition = null): ProcessOutcome
    {
        $last = $context->previous() ?? throw new ConstructionException('A finished run has at least one step');
        $status = $last->status();
        if ($last->isRejected() && $status !== null) {
            $node = $this->lastNode($context);

            return new ProcessOutcome(
                $status,
                new \stdClass(),
                $last->errors(),
                [],
                $this->declaredKey($definition, $node, $status) ?? (string) $last->messageKey(),
                $this->prefixed($status, $last->context(), $definition?->getInput($node))
            );
        }

        return new ProcessOutcome(
            ResponseStatus::Success,
            $this->response($context, $last, $definition),
            [],
            $this->events($context)
        );
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

    private function response(ProcessContext $context, NodeResult $last, ?ProcessDefinition $definition): object
    {
        return match ($definition?->getAnswer() ?? Answer::LastNode) {
            Answer::LastNode => $this->lastData($last),
            Answer::Merged => $this->mergedData($context, $definition),
        };
    }

    /**
     * The union of all steps; with a declared process answer class projected onto its fields.
     */
    private function mergedData(ProcessContext $context, ?ProcessDefinition $definition): object
    {
        $merged = $this->merged($context);
        $class = $definition?->getAnswerResponseClass();
        if ($class === null) {
            return (object) $merged;
        }

        return (new ConstructResponse())(
            'process ' . $definition->getName(),
            $class,
            $definition->getAnswerResponse() ?? [],
            $merged
        );
    }

    private function lastData(NodeResult $last): object
    {
        $data = $last->data() ?? [];
        if (!is_array($data) && !is_object($data)) {
            throw new ConstructionException('The answer of a run must be an array or an object');
        }

        return (object) $data;
    }

    /**
     * The data of all executed steps united in run order, the later step wins on the same field name.
     *
     * @return array<array-key, mixed>
     */
    private function merged(ProcessContext $context): array
    {
        $merged = [];
        foreach ($context->chain() as $step) {
            $data = $step['result']->data();
            if (is_array($data) && $data !== [] && array_is_list($data)) {
                throw new ConstructionException(
                    sprintf('A merged answer needs fields by name, step %s answered a list', $step['node'])
                );
            }
            $merged = array_replace($merged, $this->fields($data));
        }

        return $merged;
    }

    /**
     * The fields of one step: none for no data, the array itself, the public properties of an object.
     *
     * @return array<array-key, mixed>
     */
    private function fields(mixed $data): array
    {
        return match (true) {
            $data === null => [],
            is_array($data) => $data,
            is_object($data) => get_object_vars($data),
            default => throw new ConstructionException('The answer of a run must be an array or an object'),
        };
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
