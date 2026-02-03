<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Builder;

use InvalidArgumentException;
use JardisSupport\Workflow\WorkflowConfig;
use JardisPort\Workflow\WorkflowConfigInterface;

/**
 * Fluent builder for creating WorkflowConfig instances.
 *
 * Usage:
 *   $config = (new WorkflowBuilder())
 *       ->node(PaymentHandler::class)
 *           ->onSuccess(ShippingHandler::class)
 *           ->onFail(NotifyHandler::class)
 *       ->node(ShippingHandler::class)
 *           ->onSuccess(ConfirmHandler::class)
 *       ->build();
 */
class WorkflowBuilder
{
    private WorkflowConfig $config;
    private ?string $currentHandler = null;

    /** @var array<string, string|null> */
    private array $currentTransitions = [];

    public function __construct()
    {
        $this->config = new WorkflowConfig();
    }

    public function node(string $handlerClass): WorkflowNodeBuilder
    {
        $this->flushCurrentNode();
        $this->validateHandlerClass($handlerClass);

        $this->currentHandler = $handlerClass;
        $this->currentTransitions = [];

        return new WorkflowNodeBuilder($this);
    }

    public function addTransition(string $name, string $handlerClass): void
    {
        $this->currentTransitions[$name] = $handlerClass;
    }

    public function build(): WorkflowConfigInterface
    {
        $this->flushCurrentNode();

        if (empty($this->config->getNodes())) {
            throw new InvalidArgumentException('WorkflowBuilder requires at least one node');
        }

        return $this->config;
    }

    private function flushCurrentNode(): void
    {
        if ($this->currentHandler !== null) {
            $this->config->addNode($this->currentHandler, $this->currentTransitions);
            $this->currentHandler = null;
            $this->currentTransitions = [];
        }
    }

    private function validateHandlerClass(string $handlerClass): void
    {
        if (!class_exists($handlerClass)) {
            throw new InvalidArgumentException(
                sprintf('Handler class "%s" does not exist', $handlerClass)
            );
        }
    }
}
