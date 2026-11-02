<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Handler\Outcome;

/**
 * Marks the answer of a finished run with the name of the outcome that ended it.
 *
 * The name becomes the FIRST property of the answer object, under {@see self::KEY}. The marker is the package's own:
 * a step field of the same name does not survive, the marker wins and nothing is thrown (the same rule the response
 * envelope applies to its `@type`). The package knows no outcome name: what it is called is the declaration of the
 * process ({@see \JardisSupport\Workflow\Data\ProcessDefinition::answerFrom()}).
 */
final class MarkOutcome
{
    /**
     * The property name of the marker; the same literal as `ProjectOutcome::KEY` of `jardissupport/response`
     * (no shared port, the two packages agree on the literal).
     */
    public const KEY = '@outcome';

    public function __invoke(object $answer, string $outcome): object
    {
        $fields = get_object_vars($answer);
        unset($fields[self::KEY]);

        return (object) ([self::KEY => $outcome] + $fields);
    }
}
