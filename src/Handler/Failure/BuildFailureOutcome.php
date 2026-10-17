<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Handler\Failure;

use JardisSupport\Contract\Kernel\ResponseStatus;
use JardisSupport\Workflow\Data\ProcessOutcome;

/**
 * Builds the {@see ProcessOutcome} of a failed run - the one place every failure answer comes from.
 *
 * The response is the failure form of the status ({@see BuildFailureForm}). A 4xx without a form
 * answers with the message key and the context as they are; a reject of a node (`declared`) answers
 * the context alone as flat data, its key is carried on the outcome and joins the data last
 * ({@see \JardisSupport\Workflow\Handler\Response\BuildResponseData}). A 5xx answers an empty
 * object. A failed run reports no events; an unexpected failure carries its cause. A reject of a
 * node is `declared`: its message key is the one the node named and is carried on the outcome.
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
        ?\Throwable $cause = null,
        bool $declared = false
    ): ProcessOutcome {
        $form = ($this->buildFailureForm)($status, $messageKey, $context, $errors);
        $response = $form ?? match (true) {
            $status->value >= 500 => new \stdClass(),
            $declared => (object) $context,
            default => (object) ['messageKey' => $messageKey, 'context' => $context],
        };

        return new ProcessOutcome($status, $response, $errors, [], $cause, $declared ? $messageKey : null);
    }
}
