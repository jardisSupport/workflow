<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Handler\Start;

use JardisSupport\Contract\Kernel\ResponseStatus;
use JardisSupport\Contract\Validation\ValidatorInterface;
use JardisSupport\Workflow\Data\ProcessOutcome;
use JardisSupport\Workflow\Data\RunRequest;
use JardisSupport\Workflow\Handler\Failure\BuildFailureOutcome;
use JardisSupport\Workflow\Handler\Failure\DescribeFieldFinding;

/**
 * Run layer: checks the input before any node, transaction or context exists.
 *
 * Only a definition that declares a validator is checked, and always by the injected validator
 * port - the declared class is never instantiated here. A finding ends the run with a 400 whose
 * `fields` list names every failing field (`field`, `reason`, `message`, `messageKey`, see
 * {@see DescribeFieldFinding}); the next layer and so every node stay untouched. The `errors` list
 * carries the texts the validator delivered.
 */
final class ValidateInput
{
    public const MESSAGE_KEY = 'process.inputInvalid';

    public function __construct(
        private readonly ValidatorInterface $validator,
        private readonly BuildFailureOutcome $buildFailureOutcome = new BuildFailureOutcome(),
        private readonly DescribeFieldFinding $describeFieldFinding = new DescribeFieldFinding(),
    ) {
    }

    /**
     * @param \Closure(RunRequest): ProcessOutcome $next
     */
    public function __invoke(RunRequest $request, \Closure $next): ProcessOutcome
    {
        if ($request->definition->getValidatorClass() === null) {
            return $next($request);
        }

        $result = $this->validator->validate($request->input);
        if ($result->isValid()) {
            return $next($request);
        }

        $findings = $result->toFields(lcfirst((new \ReflectionClass($request->input))->getShortName()));
        $messages = array_filter(
            array_column($findings, 'message'),
            static fn(string $message): bool => $message !== ''
        );
        $fields = array_map($this->describeFieldFinding, $findings);

        return ($this->buildFailureOutcome)(
            ResponseStatus::ValidationError,
            self::MESSAGE_KEY,
            ['fields' => $fields],
            array_values($messages)
        );
    }
}
