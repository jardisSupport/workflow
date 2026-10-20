<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Handler\Context;

use JardisSupport\Workflow\Exception\ConstructionException;

/**
 * Builds a typed response object from an array of fields through the constructor of the declared class.
 *
 * The arguments are named and taken from the declared fields (none declared = all constructor fields);
 * fields the constructor does not take, or the declaration does not name, fall away. A required constructor
 * argument without a value, or a declared class that does not exist, is a {@see ConstructionException}.
 */
final class ConstructResponse
{
    /**
     * @param string $owner who declared the class, e.g. "node A" or "process B" (named in the messages)
     * @param list<string> $fields
     * @param array<array-key, mixed> $data
     * @throws ConstructionException
     */
    public function __invoke(string $owner, string $class, array $fields, array $data): object
    {
        if (!class_exists($class)) {
            throw new ConstructionException(sprintf(
                '%s declares the response class %s, which does not exist',
                ucfirst($owner),
                $class
            ));
        }
        $constructor = (new \ReflectionClass($class))->getConstructor();
        $arguments = [];
        foreach ($constructor?->getParameters() ?? [] as $parameter) {
            $name = $parameter->getName();
            $declared = $fields === [] || in_array($name, $fields, true);
            if ($declared && array_key_exists($name, $data)) {
                $arguments[$name] = $data[$name];
            } elseif (!$parameter->isOptional()) {
                throw new ConstructionException(sprintf(
                    'Response %s needs a value for the required field "%s" of %s',
                    $class,
                    $name,
                    $owner
                ));
            }
        }

        return new $class(...$arguments);
    }
}
