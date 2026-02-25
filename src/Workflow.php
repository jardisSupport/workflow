<?php

declare(strict_types=1);

namespace JardisSupport\Workflow;

use Exception;
use InvalidArgumentException;
use JardisPort\Workflow\WorkflowConfigInterface;
use JardisPort\Workflow\WorkflowInterface;

/**
 * Represents a Workflow that processes a sequence of tasks based on a configuration of nodes
 * and their success or failure paths.
 *
 * This class executes the provided workflow configuration by dynamically invoking handlers
 * for each node. It tracks the call stack and accumulates the results from each node.
 *
 * Handlers MUST return a WorkflowResult to control workflow transitions explicitly.
 */
class Workflow implements WorkflowInterface
{
    public function __construct(
        private ?\Closure $handlerFactory = null
    ) {
    }

    /**
     * @param mixed ...$parameters
     * @return array{result: array<mixed>, callStack: array<string, WorkflowResult>}
     * @throws Exception
     */
    public function __invoke(
        WorkflowConfigInterface $workflowConfig,
        mixed ...$parameters
    ): array {
        $nodes = $workflowConfig->getNodes();
        $firstNode = $nodes[0] ?? null;
        $processClass = $firstNode['handler'] ?? null;

        $callStack = [];
        $result = [];
        $parameters[] = $result;
        $position = count($parameters) - 1;

        while (is_string($processClass)) {
            $handler = $this->createHandler($processClass);

            if (!is_callable($handler)) {
                break;
            }

            $workflowResult = $handler(...$parameters);

            if (!$workflowResult instanceof WorkflowResult) {
                throw new InvalidArgumentException(sprintf(
                    'Workflow handler %s must return a WorkflowResult instance, got %s',
                    $processClass,
                    get_debug_type($workflowResult)
                ));
            }

            $callStack[$this->getNodeName($processClass)] = $workflowResult;

            $resultData = $workflowResult->getData();
            if (is_array($resultData) && !empty($resultData)) {
                $result = array_merge($result, $resultData);
                $parameters[$position] = $result;
            }

            $processClass = $this->determineNextHandler($workflowResult, $workflowConfig, $processClass);
        }

        return ['result' => $result, 'callStack' => $callStack];
    }

    private function createHandler(string $processClass): object
    {
        if ($this->handlerFactory !== null) {
            return ($this->handlerFactory)($processClass);
        }

        return new $processClass();
    }

    /**
     * Determines the next handler based on the WorkflowResult.
     *
     * Supports both:
     * - Named transitions via WorkflowResult::transition('name')
     * - Status-based routing via WorkflowResult::success() / fail()
     */
    private function determineNextHandler(
        WorkflowResult $workflowResult,
        WorkflowConfigInterface $config,
        string $currentHandler
    ): ?string {
        // Get transitions - use full transitions if available, otherwise fall back to interface
        $transitions = $this->getTransitionsForHandler($config, $currentHandler);

        if ($transitions === null) {
            return null;
        }

        // Explicit transition takes precedence (e.g., 'onRetry', 'onPending')
        if ($workflowResult->hasExplicitTransition()) {
            $transitionKey = $workflowResult->getTransition();
            return $transitions[$transitionKey] ?? null;
        }

        // Status-based routing using constants
        $transitionKey = $workflowResult->isSuccess()
            ? WorkflowResult::ON_SUCCESS
            : WorkflowResult::ON_FAIL;

        return $transitions[$transitionKey] ?? null;
    }

    /**
     * Gets transitions for a handler.
     *
     * @return array<string, string|null>|null
     */
    private function getTransitionsForHandler(WorkflowConfigInterface $config, string $handlerClass): ?array
    {
        return $config->getTransitions($handlerClass);
    }

    private function getNodeName(string $processClass): string
    {
        $lastBackslash = strrchr($processClass, '\\');
        return $lastBackslash !== false ? substr($lastBackslash, 1) : $processClass;
    }
}
