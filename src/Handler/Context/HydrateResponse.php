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
 * Applies only to a routed result with array data of a node whose definition declares a
 * response. The response class is derived from the node class (`...\Command\{Name}` becomes
 * `...\Response\{Name}Response`) and built through its constructor with named arguments taken
 * from the declared fields. A required constructor argument without a value is a
 * {@see ConstructionException}. Everything else passes unchanged, events and route included.
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
        $fields = $definition->getResponse($node);
        $route = $result->takenRoute();
        $data = $result->data();
        if ($fields === null || $route === null || !is_array($data)) {
            return $result;
        }

        return NodeResult::route($route, $this->build($node, $fields, $data))->withEvents(...$result->events());
    }

    /**
     * @param list<string> $fields
     * @param array<array-key, mixed> $data
     */
    private function build(string $node, array $fields, array $data): object
    {
        $class = $this->responseClass($node);
        $constructor = (new \ReflectionClass($class))->getConstructor();
        $arguments = [];
        foreach ($constructor?->getParameters() ?? [] as $parameter) {
            $name = $parameter->getName();
            if (in_array($name, $fields, true) && array_key_exists($name, $data)) {
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

    /**
     * @return class-string
     * @throws ConstructionException when the node class has no resolvable response class
     */
    private function responseClass(string $node): string
    {
        $class = preg_replace('/\\\\Command\\\\([^\\\\]+)$/', '\\\\Response\\\\$1Response', $node);
        if ($class === null || $class === $node || !class_exists($class)) {
            throw new ConstructionException(sprintf(
                'Node %s declares a response, but its response class (%s) does not exist',
                $node,
                $class ?? '?'
            ));
        }

        return $class;
    }
}
