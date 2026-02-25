<?php

declare(strict_types=1);

namespace JardisSupport\Workflow;

use InvalidArgumentException;
use JardisPort\Workflow\WorkflowConfigInterface;

/**
 * Represents the configuration for a workflow process.
 *
 * Allows the addition of workflow nodes with associated handlers and
 * flexible transition mappings for various scenarios (success, fail, retry, etc.).
 *
 * Usage:
 *   $config->addNode(PaymentHandler::class, [
 *       WorkflowResult::ON_SUCCESS => ShippingHandler::class,
 *       WorkflowResult::ON_FAIL    => NotifyHandler::class,
 *       WorkflowResult::ON_RETRY   => PaymentHandler::class,
 *   ]);
 */
class WorkflowConfig implements WorkflowConfigInterface
{
    /** @var array<int, array{handler: string, transitions: array<string, string|null>}> */
    private array $nodes = [];

    /** @var array<string, int> Maps handler class to node index */
    private array $nodeIndex = [];

    /**
     * Adds a node to the workflow configuration.
     *
     * @param string $handlerClass The handler class to execute
     * @param array<string, string|null> $transitions Map of transition names to handler classes
     */
    public function addNode(
        string $handlerClass,
        array $transitions = []
    ): self {
        $this->validateHandlerClass($handlerClass);

        if (isset($this->nodeIndex[$handlerClass])) {
            return $this;
        }

        $index = count($this->nodes);
        $this->nodes[$index] = [
            'handler' => $handlerClass,
            'transitions' => $transitions,
        ];
        $this->nodeIndex[$handlerClass] = $index;

        return $this;
    }

    /**
     * Returns all configured nodes with their transitions.
     *
     * @return array<int, array{handler: string, transitions: array<string, string|null>}>
     */
    public function getNodes(): array
    {
        return $this->nodes;
    }

    /**
     * Returns the full transitions array for a handler.
     *
     * @return array<string, string|null>|null
     */
    public function getTransitions(string $handlerClass): ?array
    {
        if (!isset($this->nodeIndex[$handlerClass])) {
            return null;
        }

        $index = $this->nodeIndex[$handlerClass];
        return $this->nodes[$index]['transitions'];
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
