<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Handler\Context;

use JardisSupport\Workflow\Data\NodeResult;
use JardisSupport\Workflow\Data\ProcessContext;
use JardisSupport\Workflow\Data\ProcessDefinition;
use JardisSupport\Workflow\Exception\ConstructionException;

/**
 * Node decorator: turns the array data of a node into its typed response object.
 *
 * Applies only to a routed result with array data of a node whose definition declares a response
 * class; without a declaration the data stays as it is. The object is built through the constructor
 * of the declared class with named arguments taken from the declared fields (none declared = all
 * constructor fields). A required constructor argument without a value, or a declared class that
 * does not exist, is a {@see ConstructionException}. Everything else passes unchanged, events and
 * route included.
 */
final class HydrateResponse
{
    /**
     * @param \Closure(ProcessContext): NodeResult $next
     * @throws ConstructionException
     */
    public function __invoke(
        ProcessDefinition $definition,
        string $node,
        ProcessContext $context,
        \Closure $next
    ): NodeResult {
        $result = $next($context);
        $class = $definition->getResponseClass($node);
        $route = $result->takenRoute();
        $data = $result->data();
        if ($class === null || $route === null || !is_array($data)) {
            return $result;
        }

        return NodeResult::route($route, $this->build($node, $class, $definition->getResponse($node) ?? [], $data))
            ->withEvents(...$result->events());
    }

    /**
     * @param list<string> $fields
     * @param array<array-key, mixed> $data
     * @throws ConstructionException
     */
    private function build(string $node, string $class, array $fields, array $data): object
    {
        if (!class_exists($class)) {
            throw new ConstructionException(sprintf(
                'Node %s declares the response class %s, which does not exist',
                $node,
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
                    'Response %s needs a value for the required field "%s" of node %s',
                    $class,
                    $name,
                    $node
                ));
            }
        }

        return new $class(...$arguments);
    }
}
