<?php

declare(strict_types=1);

namespace JardisSupport\Workflow;

use InvalidArgumentException;
use JardisSupport\Contract\Workflow\WorkflowConfigInterface;

/**
 * Represents the configuration for a workflow process.
 *
 * Allows the addition of workflow nodes with associated handlers and
 * flexible transition mappings for the seven named statuses (success, fail,
 * timeout, skip, cancel, event, exit).
 *
 * Usage:
 *   $config->addNode(PaymentHandler::class, [
 *       WorkflowResult::ON_SUCCESS => ShippingHandler::class,
 *       WorkflowResult::ON_FAIL    => NotifyHandler::class,
 *       WorkflowResult::ON_SKIP    => PaymentHandler::class,
 *   ]);
 */
class WorkflowConfig implements WorkflowConfigInterface
{
    /** @var array<int, array{handler: string, transitions: array<string, string|null>}> */
    private array $nodes = [];

    /** @var array<string, int> Maps handler class to node index */
    private array $nodeIndex = [];

    /**
     * @param bool $strictRouting Opt-in (default false, byte-identical to pre-existing behaviour
     *        when omitted): when true, the engine requires every status a handler can emit to be
     *        a declared transition key for that node — either mapped to a handler class or
     *        explicitly mapped to null (a deliberate terminal end). A status with no key at all
     *        raises {@see \JardisSupport\Workflow\Exception\UnroutedStatusException}. The
     *        R5 routing-safety hand-off (a mapped target that is not itself a registered node)
     *        is unaffected by this flag either way.
     */
    public function __construct(
        private readonly bool $strictRouting = false
    ) {
    }

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

    /**
     * Whether strict routing is enabled for this config (see constructor doc-block).
     */
    public function isStrictRouting(): bool
    {
        return $this->strictRouting;
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
