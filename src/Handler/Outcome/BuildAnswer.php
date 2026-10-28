<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Handler\Outcome;

use JardisSupport\Workflow\Data\ProcessContext;
use JardisSupport\Workflow\Data\ProcessDefinition;
use JardisSupport\Workflow\Exception\ConstructionException;

/**
 * Builds the answer of a finished run from the entries a process declares with {@see ProcessDefinition::answerFrom()}.
 *
 * Per entry the LAST executed step of any of its candidate nodes gives the entry; the data is flat: an array as it
 * is, an object by its public properties, no data adds no field. The entries are united into one object. The
 * package knows no field name: what the fields are called is the data of the steps.
 */
final class BuildAnswer
{
    /**
     * @param list<list<string>> $entries each entry a list of candidate node ids
     * @throws ConstructionException when an entry has no executed candidate, a step answered a list or a scalar,
     *                               or two entries give the same field name
     */
    public function __invoke(ProcessContext $context, array $entries): object
    {
        $answer = [];
        foreach ($entries as $position => $candidates) {
            $step = $this->lastStep($context, $candidates, $position + 1);
            foreach ($this->fields($step['node'], $step['data']) as $name => $value) {
                if (array_key_exists($name, $answer)) {
                    throw new ConstructionException(sprintf(
                        'The answer names the field "%s" twice (step %s gives it again)',
                        $name,
                        $step['node']
                    ));
                }
                $answer[$name] = $value;
            }
        }

        return (object) $answer;
    }

    /**
     * @param list<string> $candidates
     * @return array{node: string, data: mixed}
     * @throws ConstructionException
     */
    private function lastStep(ProcessContext $context, array $candidates, int $entry): array
    {
        foreach (array_reverse($context->chain()) as $step) {
            if (in_array($step['node'], $candidates, true)) {
                return ['node' => $step['node'], 'data' => $step['result']->data()];
            }
        }

        throw new ConstructionException(sprintf(
            'Answer entry %d (%s) was not given: none of its nodes ran',
            $entry,
            implode(', ', $candidates)
        ));
    }

    /**
     * @return array<array-key, mixed>
     * @throws ConstructionException
     */
    private function fields(string $node, mixed $data): array
    {
        if (is_array($data) && $data !== [] && array_is_list($data)) {
            throw new ConstructionException(
                sprintf('An answer entry needs fields by name, step %s answered a list', $node)
            );
        }

        return match (true) {
            $data === null => [],
            is_array($data) => $data,
            is_object($data) => get_object_vars($data),
            default => throw new ConstructionException(
                sprintf('The data of step %s must be an array or an object', $node)
            ),
        };
    }
}
