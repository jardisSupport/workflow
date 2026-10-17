<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Data\Builder;

use JardisSupport\Workflow\Data\Route;
use JardisSupport\Workflow\Data\WorkflowConfig;
use JardisSupport\Workflow\Exception\ConstructionException;

/**
 * Collects nodes and their route targets and validates them into a {@see WorkflowConfig}.
 *
 * @internal Used by ProcessDefinition only.
 */
final class WorkflowConfigBuilder
{
    /** @var array<string, array<string, string|null>> */
    private array $transitions = [];

    public function hasNode(string $node): bool
    {
        return array_key_exists($node, $this->transitions);
    }

    /**
     * @throws ConstructionException on an empty name or a duplicate node
     */
    public function addNode(string $node): void
    {
        if ($node === '') {
            throw new ConstructionException('A node needs a non-empty class name');
        }
        if ($this->hasNode($node)) {
            throw new ConstructionException(sprintf('Node "%s" is already declared', $node));
        }

        $this->transitions[$node] = [];
    }

    /**
     * Maps a route of a declared node to a target; null declares a terminal end.
     *
     * @throws ConstructionException when the node is not declared
     */
    public function route(string $node, Route $route, ?string $target): void
    {
        if (!$this->hasNode($node)) {
            throw new ConstructionException(sprintf('Node "%s" is not declared', $node));
        }

        $this->transitions[$node][$route->value] = $target;
    }

    /**
     * @throws ConstructionException on an empty graph or a target that is not a declared node
     */
    public function build(): WorkflowConfig
    {
        if ($this->transitions === []) {
            throw new ConstructionException('A process needs at least one node');
        }

        foreach ($this->transitions as $node => $routes) {
            foreach ($routes as $key => $target) {
                if ($target !== null && !$this->hasNode($target)) {
                    throw new ConstructionException(sprintf(
                        'Node "%s" routes %s to unknown target "%s"',
                        $node,
                        $key,
                        $target
                    ));
                }
            }
        }

        return new WorkflowConfig($this->transitions);
    }
}
