<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Handler\Response;

use JardisSupport\Workflow\Data\Failure\Conflict;
use JardisSupport\Workflow\Data\Failure\FailureForm;
use JardisSupport\Workflow\Data\Failure\NotFound;
use JardisSupport\Workflow\Data\Failure\RuleViolation;
use JardisSupport\Workflow\Data\Failure\ValidationFailure;
use JardisSupport\Workflow\Data\ProcessOutcome;
use stdClass;

/**
 * Builds the `data` of the response envelope from the {@see ProcessOutcome}.
 *
 * The one place the answer of a process is turned into wire data:
 * - success: `@type` (the process name, first letter lower case) plus the public fields of the response;
 * - 400 `validation`: `@type`, `fields`; 404 `notFound`: `@type`, `context`;
 *   409 `concurrencyConflict`: `@type`, `reason`, `aggregate`, `context`, `violated`;
 *   422 `ruleViolation`: `@type`, `rule`, `messageKey`, `context`;
 * - the message key a node declared with its reject joins the data of every 4xx, the keys of the
 *   package's own layers do not; the 422 form always carries its own; an empty `context` encodes as `{}`;
 * - another 4xx passes its response fields through unchanged, a 5xx answers no data.
 *
 * Atomic closure - one entry point.
 */
final class BuildResponseData
{
    /**
     * @return array<string, mixed>
     */
    public function __invoke(ProcessOutcome $outcome, string $processName): array
    {
        $response = $outcome->response();

        $data = match (true) {
            $response instanceof FailureForm => $this->failure($response),
            $outcome->status()->value >= 400 => get_object_vars($response),
            default => ['@type' => lcfirst($processName)] + get_object_vars($response),
        };

        $declared = $outcome->declaredMessageKey();
        if ($declared !== null && $declared !== '' && $outcome->status()->value >= 400) {
            $data['messageKey'] = $declared;
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function failure(FailureForm $form): array
    {
        return match (true) {
            $form instanceof ValidationFailure => ['@type' => 'validation', 'fields' => $form->fields()],
            $form instanceof NotFound => ['@type' => 'notFound', 'context' => $this->context($form)],
            $form instanceof Conflict => [
                '@type' => 'concurrencyConflict',
                'reason' => (string) $form->reason(),
                'aggregate' => (string) $form->aggregate(),
                'context' => $this->context($form),
                'violated' => $form->violated(),
            ],
            $form instanceof RuleViolation => [
                '@type' => 'ruleViolation',
                'rule' => $form->rule(),
                'messageKey' => $form->messageKey(),
                'context' => $this->context($form),
            ],
            default => [],
        };
    }

    /**
     * @return array<string, mixed>|stdClass
     */
    private function context(FailureForm $form): array|stdClass
    {
        return $form->context() ?: new stdClass();
    }
}
