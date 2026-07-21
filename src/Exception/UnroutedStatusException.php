<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Exception;

use RuntimeException;

/**
 * Thrown by the engine in strict-routing mode (see WorkflowConfig::isStrictRouting())
 * when a handler returns a status for which the current node's transition map has no
 * key at all.
 *
 * This is distinct from a status explicitly mapped to null — that is a deliberate,
 * declared terminal end and is legitimate even in strict mode. An unrouted status is
 * the case strict routing exists to catch: a status the map neither routes further
 * nor declares as an intentional stop.
 */
class UnroutedStatusException extends RuntimeException
{
    public function __construct(
        private readonly string $node,
        private readonly string $status,
    ) {
        parent::__construct(sprintf(
            'Unrouted status "%s" for handler "%s": strict routing requires every status a handler ' .
            'can emit to be a declared transition key (mapped to a handler class, or explicit null ' .
            'for a deliberate terminal end)',
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
