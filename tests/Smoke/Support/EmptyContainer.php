<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Tests\Smoke\Support;

use Psr\Container\ContainerInterface;

/**
 * The kernel container of the smoke: holds nothing, the generated handlers never resolve through it.
 */
final class EmptyContainer implements ContainerInterface
{
    public function get(string $id): never
    {
        throw new class ('No service ' . $id) extends \RuntimeException implements
            \Psr\Container\NotFoundExceptionInterface {
        };
    }

    public function has(string $id): bool
    {
        return false;
    }
}
