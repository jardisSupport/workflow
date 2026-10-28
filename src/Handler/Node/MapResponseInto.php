<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Handler\Node;

use JardisSupport\Contract\Kernel\DomainResponseInterface;
use JardisSupport\Workflow\Data\NodeResult;
use JardisSupport\Workflow\Data\Route;
use JardisSupport\Workflow\Exception\ConstructionException;
use JardisSupport\Workflow\Handler\Context\ConstructResponse;

/**
 * Maps the answer of a called domain operation like {@see MapResponse} - and turns the data of a 2xx into an object
 * of the given class (the typed reference of the call).
 *
 * The status mapping, the Domain events and the reject are those of {@see MapResponse} (injected, so there is one
 * mapping). Only a 2xx changes: a class with a constructor is built by {@see ConstructResponse}; a class without
 * one is filled through its PUBLIC properties, iterated on the class side, so keys the class does not know
 * (`@type`, `id`, ...) fall away. A property without a default needs a value in the data. A missing key, a value of
 * the wrong type, a `readonly` property, an unknown or not instantiable class is a {@see ConstructionException}.
 * A 4xx or the route of `$onReject` stays exactly what MapResponse answers.
 */
final class MapResponseInto
{
    public function __construct(private readonly MapResponse $map = new MapResponse())
    {
    }

    /**
     * @param string $into the class of the typed answer
     * @throws ConstructionException when the data does not fit the class
     * @throws \JardisSupport\Workflow\Exception\CalleeFailureException on a 5xx
     * @throws \RuntimeException on any other status without a node meaning
     */
    public function __invoke(DomainResponseInterface $response, string $into, ?Route $onReject = null): NodeResult
    {
        $result = ($this->map)($response, $onReject);
        $status = $response->getStatus();
        if ($status < 200 || $status >= 300) {
            return $result;
        }

        $data = $result->data();

        return NodeResult::ok($this->typed($into, is_array($data) ? $data : []))->withEvents(...$result->events());
    }

    /**
     * @param array<array-key, mixed> $data
     * @throws ConstructionException
     */
    private function typed(string $into, array $data): object
    {
        if (!class_exists($into)) {
            throw new ConstructionException(
                sprintf('MapResponseInto names the class %s, which does not exist', $into)
            );
        }
        $class = new \ReflectionClass($into);
        if (!$class->isInstantiable()) {
            throw new ConstructionException(
                sprintf('MapResponseInto names the class %s, which cannot be instantiated', $into)
            );
        }

        try {
            return $class->getConstructor() !== null
                ? (new ConstructResponse())('MapResponseInto', $into, [], $data)
                : $this->filled($class, $data);
        } catch (\TypeError $error) {
            throw new ConstructionException(
                sprintf('The data does not fit the class %s: %s', $into, $error->getMessage()),
                0,
                $error
            );
        }
    }

    /**
     * @param \ReflectionClass<object> $class
     * @param array<array-key, mixed> $data
     * @throws ConstructionException
     */
    private function filled(\ReflectionClass $class, array $data): object
    {
        $properties = array_filter(
            $class->getProperties(\ReflectionProperty::IS_PUBLIC),
            static fn(\ReflectionProperty $property): bool => !$property->isStatic()
        );
        foreach ($properties as $property) {
            if ($property->isReadOnly()) {
                throw new ConstructionException(sprintf(
                    'Class %s cannot be filled: property "%s" is readonly and the class has no constructor',
                    $class->getName(),
                    $property->getName()
                ));
            }
        }

        $object = $class->newInstance();
        foreach ($properties as $property) {
            $name = $property->getName();
            if (array_key_exists($name, $data)) {
                $object->$name = $data[$name];
            } elseif (!$property->hasDefaultValue()) {
                throw new ConstructionException(sprintf(
                    'Response %s needs a value for the required field "%s" of MapResponseInto',
                    $class->getName(),
                    $name
                ));
            }
        }

        return $object;
    }
}
