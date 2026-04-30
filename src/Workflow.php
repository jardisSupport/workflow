<?php

declare(strict_types=1);

namespace JardisSupport\Workflow;

use InvalidArgumentException;
use JardisSupport\Contract\Workflow\WorkflowConfigInterface;
use JardisSupport\Contract\Workflow\WorkflowContextInterface;
use JardisSupport\Contract\Workflow\WorkflowInterface;
use JardisSupport\Contract\Workflow\WorkflowResultInterface;

/**
 * Executes a configured chain of workflow handlers.
 *
 * Each handler is invoked with the original $parameters plus a WorkflowContext
 * appended as the last argument. The handler returns a WorkflowResult which is
 * appended to the context under the handler's fully-qualified class name and
 * exposed to the next handler via WorkflowContext::getPrevious().
 *
 * Routing supports both status-based (success/fail) and named transitions
 * (onRetry, onPending, onCancel, ...).
 */
class Workflow implements WorkflowInterface
{
    public function __construct(
        private ?\Closure $handlerFactory = null
    ) {
    }

    /**
     * @param mixed ...$parameters Parameters passed ahead of the context to each handler
     * @throws InvalidArgumentException
     */
    public function __invoke(
        WorkflowConfigInterface $workflowConfig,
        mixed ...$parameters
    ): WorkflowContextInterface {
        $nodes = $workflowConfig->getNodes();
        $firstNode = $nodes[0] ?? null;
        $processClass = $firstNode['handler'] ?? null;

        $context = new WorkflowContext();
        $parameters[] = $context;

        while (is_string($processClass)) {
            /** @var class-string $processClass — guaranteed by WorkflowConfig::addNode() validation */
            $handler = $this->createHandler($processClass);

            if (!is_callable($handler)) {
                throw new InvalidArgumentException(sprintf(
                    'Workflow handler %s is not callable',
                    $processClass
                ));
            }

            $workflowResult = $handler(...$parameters);

            if (!$workflowResult instanceof WorkflowResultInterface) {
                throw new InvalidArgumentException(sprintf(
                    'Workflow handler %s must return a WorkflowResultInterface instance, got %s',
                    $processClass,
                    get_debug_type($workflowResult)
                ));
            }

            $context->append($processClass, $workflowResult);

            $processClass = $this->determineNextHandler($workflowResult, $workflowConfig, $processClass);
        }

        return $context;
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
     * - Named transitions via WorkflowResult::ON_* constants
     * - Status-based routing via WorkflowResult::STATUS_SUCCESS / STATUS_FAIL
     */
    private function determineNextHandler(
        WorkflowResultInterface $workflowResult,
        WorkflowConfigInterface $config,
        string $currentHandler
    ): ?string {
        $transitions = $config->getTransitions($currentHandler);

        if ($transitions === null) {
            return null;
        }

        if ($workflowResult->hasExplicitTransition()) {
            $transitionKey = $workflowResult->getTransition();
            return $transitions[$transitionKey] ?? null;
        }

        $transitionKey = $workflowResult->isSuccess()
            ? WorkflowResultInterface::ON_SUCCESS
            : WorkflowResultInterface::ON_FAIL;

        return $transitions[$transitionKey] ?? null;
    }
}
