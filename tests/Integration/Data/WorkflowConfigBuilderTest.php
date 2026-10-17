<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Tests\Integration\Data;

use JardisSupport\Workflow\Data\Builder\WorkflowConfigBuilder;
use JardisSupport\Workflow\Data\Route;
use JardisSupport\Workflow\Exception\ConstructionException;
use PHPUnit\Framework\TestCase;

/**
 * WorkflowConfigBuilder and the WorkflowConfig it produces (both @internal).
 */
class WorkflowConfigBuilderTest extends TestCase
{
    public function testBuildsOrderedGraphWithEntryNode(): void
    {
        $builder = new WorkflowConfigBuilder();
        $builder->addNode('A');
        $builder->addNode('B');
        $builder->route('A', Route::OnTrue, 'B');
        $builder->route('A', Route::OnFalse, null);

        $config = $builder->build();

        $this->assertSame('A', $config->entry());
        $this->assertSame(['A', 'B'], $config->nodes());
        $this->assertTrue($config->has('B'));
        $this->assertFalse($config->has('Z'));
        $this->assertSame(['onTrue' => 'B', 'onFalse' => null], $config->transitions('A'));
        $this->assertSame([], $config->transitions('B'));
        $this->assertSame([], $config->transitions('Z'));
    }

    public function testEmptyGraphFailsConstruction(): void
    {
        $this->expectException(ConstructionException::class);

        (new WorkflowConfigBuilder())->build();
    }

    public function testDuplicateNodeFailsConstruction(): void
    {
        $builder = new WorkflowConfigBuilder();
        $builder->addNode('A');

        $this->expectException(ConstructionException::class);

        $builder->addNode('A');
    }

    public function testRouteOnUndeclaredNodeFailsConstruction(): void
    {
        $this->expectException(ConstructionException::class);

        (new WorkflowConfigBuilder())->route('A', Route::OnTrue, null);
    }

    public function testUnknownTargetFailsAtBuild(): void
    {
        $builder = new WorkflowConfigBuilder();
        $builder->addNode('A');
        $builder->route('A', Route::OnTrue, 'Missing');

        $this->expectException(ConstructionException::class);

        $builder->build();
    }
}
