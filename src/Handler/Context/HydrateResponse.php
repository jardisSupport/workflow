<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Handler\Context;

use JardisSupport\Workflow\Data\NodeResult;
use JardisSupport\Workflow\Data\ProcessContext;
use JardisSupport\Workflow\Data\ProcessDefinition;
use JardisSupport\Workflow\Data\Route;
use JardisSupport\Workflow\Exception\ConstructionException;

/**
 * Node decorator: turns the array data of a node into its typed response object.
 *
 * Applies only to a routed result with array data of a node whose definition declares a response
 * class; without a declaration the data stays as it is. The object is built through the constructor
 * of the declared class with named arguments taken from the declared fields (none declared = all
 * constructor fields). A required constructor argument without a value, or a declared class that
 * does not exist, is a {@see ConstructionException} ({@see ConstructResponse}). Everything else passes
 * unchanged, events and route included; so does a terminal end of a process that declares its own answer
 * class, because the process answer is not the class of its last node.
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
        if ($this->answersProcess($definition, $node, $route)) {
            return $result;
        }

        return NodeResult::route(
            $route,
            (new ConstructResponse())('node ' . $node, $class, $definition->getResponse($node) ?? [], $data)
        )->withEvents(...$result->events());
    }

    /**
     * A terminal end of a process that declares its own answer class: the process answer is projected from the
     * united steps ({@see ProcessDefinition::answerResponse()}), the end node's class is not applied.
     */
    private function answersProcess(ProcessDefinition $definition, string $node, Route $route): bool
    {
        $transitions = $definition->config()->transitions($node);

        return $definition->getAnswerResponseClass() !== null
            && array_key_exists($route->value, $transitions)
            && $transitions[$route->value] === null;
    }
}
