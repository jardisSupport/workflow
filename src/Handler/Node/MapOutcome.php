<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Handler\Node;

use JardisSupport\Workflow\Data\Failure\Conflict;
use JardisSupport\Workflow\Data\Failure\FailureForm;
use JardisSupport\Workflow\Data\Failure\RuleViolation;
use JardisSupport\Workflow\Data\Failure\ValidationFailure;
use JardisSupport\Workflow\Data\NodeResult;
use JardisSupport\Workflow\Data\ProcessOutcome;
use JardisSupport\Workflow\Data\Route;

/**
 * Maps the outcome of an inner process run (a sub-process node) to the result of the calling node.
 *
 * - success: `ok((array) response)` carrying all events of the inner run, so they travel up to the outer answer;
 * - 4xx with `$onReject`: `route($onReject)` - the dev decision to carry on instead of refusing;
 * - 4xx without: a reject with the SAME status. The failure form of the inner answer is unpacked into
 *   the keys {@see \JardisSupport\Workflow\Handler\Failure\BuildFailureForm} reads (400 `fields`, 409
 *   `reason`/`aggregate`/`violated`, 422 `rule`, the rest as plain context), so the outer process answers
 *   the same form again - no form inside a form. The message key follows {@see MapResponse}: the key the
 *   inner node declared (and, for 422, the key of its own form) travels, the keys of the package's own
 *   layers (`process.inputInvalid`, ...) never do. The errors of the inner run travel on;
 * - 5xx: throws `cause()`; without a cause a `RuntimeException` naming the process (when given) and the status.
 *
 * Atomic closure - one entry point.
 */
final class MapOutcome
{
    /**
     * @param string $process name of the inner process, only used in the message of a 5xx without a cause
     * @throws \Throwable the cause of a 5xx
     */
    public function __invoke(ProcessOutcome $outcome, ?Route $onReject = null, string $process = ''): NodeResult
    {
        $status = $outcome->status();
        if ($status->value >= 500) {
            throw $outcome->cause() ?? new \RuntimeException(sprintf(
                'Sub-process%s failed with status %d (%s)',
                $process === '' ? '' : ' ' . $process,
                $status->value,
                $status->name
            ));
        }
        if ($status->value >= 400) {
            return $onReject !== null
                ? NodeResult::route($onReject)
                : NodeResult::reject(
                    $status,
                    $this->messageKey($outcome),
                    $this->context($outcome),
                    $outcome->errors()
                );
        }

        return NodeResult::ok((array) $outcome->response())->withEvents(...$outcome->events());
    }

    /**
     * The key of the inner node's declaration; the 422 form always carries its own.
     */
    private function messageKey(ProcessOutcome $outcome): string
    {
        $declared = $outcome->declaredMessageKey();
        $response = $outcome->response();

        return $declared ?? ($response instanceof RuleViolation ? $response->messageKey() : '');
    }

    /**
     * @return array<string, mixed>
     */
    private function context(ProcessOutcome $outcome): array
    {
        $response = $outcome->response();
        if ($response instanceof FailureForm) {
            return $this->ownFields($response) + $response->context();
        }

        // A 4xx without a form: a node's reject passes its fields on, a layer failure names none.
        return $outcome->declaredMessageKey() === null ? [] : get_object_vars($response);
    }

    /**
     * The fields only this form has, under the keys the failure-form builder reads them from.
     *
     * @return array<string, mixed>
     */
    private function ownFields(FailureForm $form): array
    {
        return match (true) {
            $form instanceof ValidationFailure => ['fields' => $form->fields()],
            $form instanceof Conflict => array_filter([
                'reason' => $form->reason(),
                'aggregate' => $form->aggregate(),
                'violated' => $form->violated(),
            ], static fn(mixed $value): bool => $value !== null && $value !== []),
            $form instanceof RuleViolation => ['rule' => $form->rule()],
            default => [],
        };
    }
}
