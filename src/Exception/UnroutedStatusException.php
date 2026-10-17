<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Exception;

/**
 * Thrown by the engine when a node returns a route for which its transition map has no key at all.
 *
 * This is distinct from a route explicitly mapped to null - that is a deliberate,
 * declared terminal end and legitimate. An unrouted route is the case the engine's
 * always-on strict routing exists to catch: a route the map neither routes further
 * nor declares as an intentional stop.
 */
final class UnroutedStatusException extends WorkflowException
{
    public function __construct(
        private readonly string $node,
        private readonly string $status,
    ) {
        parent::__construct(sprintf(
            'Unrouted status "%s" for node "%s": every route a node can emit must be a declared ' .
            'transition key (mapped to a node, or explicit null for a deliberate terminal end)',
            $status,
            $node
        ));
    }

    public function getNode(): string
    {
        return $this->node;
    }

    public function getStatus(): string
    {
        return $this->status;
    }
}
