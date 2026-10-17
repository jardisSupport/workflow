<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Tests\Unit\Exception;

use JardisSupport\Workflow\Exception\ConfigurationException;
use JardisSupport\Workflow\Exception\ConstructionException;
use JardisSupport\Workflow\Exception\LoopLimitExceededException;
use JardisSupport\Workflow\Exception\UnroutedStatusException;
use JardisSupport\Workflow\Exception\WorkflowException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Pure logic of the exception set: one abstract base, four leaves, accessors.
 */
class ExceptionHierarchyTest extends TestCase
{
    public function testBaseIsAbstractRuntimeException(): void
    {
        $base = new ReflectionClass(WorkflowException::class);

        $this->assertTrue($base->isAbstract());
        $this->assertTrue($base->isSubclassOf(\RuntimeException::class));
    }

    public function testEveryLeafExtendsTheBase(): void
    {
        $leaves = [
            new ConstructionException('c'),
            new ConfigurationException('c'),
            new LoopLimitExceededException(3),
            new UnroutedStatusException('Node', 'onTrue'),
        ];

        foreach ($leaves as $leaf) {
            $this->assertInstanceOf(WorkflowException::class, $leaf);
        }
    }

    public function testUnroutedStatusExceptionKeepsNodeAndStatus(): void
    {
        $e = new UnroutedStatusException('Node\\A', 'onFalse');

        $this->assertSame('Node\\A', $e->getNode());
        $this->assertSame('onFalse', $e->getStatus());
        $this->assertStringContainsString('onFalse', $e->getMessage());
        $this->assertStringContainsString('Node\\A', $e->getMessage());
    }

    public function testLoopLimitExceededExceptionKeepsLimit(): void
    {
        $e = new LoopLimitExceededException(100);

        $this->assertSame(100, $e->getLimit());
        $this->assertStringContainsString('100', $e->getMessage());
    }
}
