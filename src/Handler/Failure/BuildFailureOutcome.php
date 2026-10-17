<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Handler\Failure;

use JardisSupport\Contract\Kernel\ResponseStatus;
use JardisSupport\Workflow\Data\ProcessOutcome;

/**
 * Builds the {@see ProcessOutcome} of a failed run - the one place every failure answer comes from.
 *
 * The response is the failure form of the status ({@see BuildFailureForm}). A 4xx without a form
 * answers with the message key and the context as they are, a 5xx with an empty object. A failed
 * run reports no events; an unexpected failure carries its cause.
 */
final class BuildFailureOutcome
{
    public function __construct(private readonly BuildFailureForm $buildFailureForm = new BuildFailureForm())
    {
    }

    /**
     * @param array<string, mixed> $context
     * @param list<string> $errors
     */
    public function __invoke(
        ResponseStatus $status,
        string $messageKey,
        array $context = [],
        array $errors = [],
        ?\Throwable $cause = null
    ): ProcessOutcome {
        $form = ($this->buildFailureForm)($status, $messageKey, $context, $errors);
        $response = $form ?? ($status->value < 500
            ? (object) ['messageKey' => $messageKey, 'context' => $context]
            : new \stdClass());

        return new ProcessOutcome($status, $response, $errors, [], $cause);
    }
}
