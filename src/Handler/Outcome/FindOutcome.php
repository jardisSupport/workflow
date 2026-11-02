<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Handler\Outcome;

use JardisSupport\Workflow\Data\ProcessContext;
use JardisSupport\Workflow\Exception\ConstructionException;

/**
 * Finds the outcome name of a finished run: the name of the candidate node that ran last.
 *
 * The named form of {@see \JardisSupport\Workflow\Data\ProcessDefinition::answerFrom()} maps each candidate node to an
 * outcome name; the candidate that ran last ends the run. The package knows no outcome name: what it is called is the
 * declaration of the process. {@see MarkOutcome} sets the name on the answer.
 */
final class FindOutcome
{
    /**
     * @param array<string, string> $named node id => outcome name
     * @throws ConstructionException when none of the candidate nodes ran
     */
    public function __invoke(ProcessContext $context, array $named): string
    {
        foreach (array_reverse($context->chain()) as $step) {
            if (array_key_exists($step['node'], $named)) {
                return $named[$step['node']];
            }
        }

        throw new ConstructionException(sprintf(
            'Answer entry %d (%s) was not given: none of its nodes ran',
            1,
            implode(', ', array_keys($named))
        ));
    }
}
