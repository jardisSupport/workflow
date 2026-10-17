<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Tests\Support;

use JardisSupport\Contract\Kernel\ResponseStatus;
use JardisSupport\Workflow\Data\ProcessDefinition;
use JardisSupport\Workflow\Data\Route;

/**
 * Test helper: builds a {@see ProcessDefinition} from a legacy process JSON document.
 *
 * Mapping: the first declared node is the start node, `onSuccess` becomes OnTrue, `onFail`
 * OnFalse, a `terminal` edge a null target, `runInTransaction` the transactional flag, a 4xx
 * `responses` entry a reject declaration. A node name N of process P becomes the class
 * `Tests\Support\Process\P\Command\N`.
 */
final class LoadDefinition
{
    private const ROUTE_KEYS = ['onSuccess' => 'onTrue', 'onFail' => 'onFalse'];

    /**
     * @throws \JsonException
     */
    public function __invoke(string $json): ProcessDefinition
    {
        /** @var array<string, mixed> $document */
        $document = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $process = (string) $document['name'];
        $definition = ProcessDefinition::for(self::namespaceOf($process) . '\\Input', $process)
            ->transactional((bool) ($document['runInTransaction'] ?? true));

        /** @var list<array<string, mixed>> $nodes */
        $nodes = $document['nodes'];
        /** @var list<array<string, string|bool>> $edges */
        $edges = $document['edges'];
        usort($nodes, static fn(array $a, array $b): int => ($b['id'] === $document['start']) <=> ($a['id'] === $document['start']));

        $classes = array_column($nodes, 'name', 'id');
        foreach ($nodes as $node) {
            $definition->node(self::classOf($process, (string) $node['name']));
            $this->declareEdges($definition, $process, $classes, $edges, (string) $node['id']);
            $this->declareRejects($definition, $node);
        }

        return $definition->build();
    }

    public static function classOf(string $process, string $node): string
    {
        return self::namespaceOf($process) . '\\Command\\' . $node;
    }

    private static function namespaceOf(string $process): string
    {
        return 'JardisSupport\\Workflow\\Tests\\Support\\Process\\' . $process;
    }

    /**
     * @param array<string, string> $classes node id => node name
     * @param list<array<string, string|bool>> $edges
     */
    private function declareEdges(
        ProcessDefinition $definition,
        string $process,
        array $classes,
        array $edges,
        string $id
    ): void {
        foreach ($edges as $edge) {
            if ($edge['from'] !== $id) {
                continue;
            }
            $on = (string) $edge['on'];
            $route = Route::from(self::ROUTE_KEYS[$on] ?? $on);
            $target = isset($edge['to']) ? self::classOf($process, $classes[(string) $edge['to']]) : null;
            $definition->{lcfirst($route->name)}($target);
        }
    }

    /**
     * @param array<string, mixed> $node
     */
    private function declareRejects(ProcessDefinition $definition, array $node): void
    {
        /** @var list<array<string, mixed>> $responses */
        $responses = $node['responses'] ?? [];
        foreach ($responses as $response) {
            $status = (int) $response['status'];
            if ($status >= 400 && $status < 500) {
                $definition->rejects(ResponseStatus::from($status), (string) $response['messageKey']);
            }
        }
    }
}
