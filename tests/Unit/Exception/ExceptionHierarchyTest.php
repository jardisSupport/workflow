<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Tests\Unit\Exception;

use JardisSupport\Workflow\Exception\ConstructionException;
use JardisSupport\Workflow\Exception\ExternalFailureException;
use JardisSupport\Workflow\Exception\LoopLimitExceededException;
use JardisSupport\Workflow\Exception\UnroutedStatusException;
use JardisSupport\Workflow\Exception\WorkflowException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Pure logic of the exception set: one abstract base, three leaves, accessors.
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

    public function testExternalFailureIsAFinalRuntimeExceptionWithStatusAndReason(): void
    {
        $e = new ExternalFailureException(403, 'no right');

        $this->assertTrue((new ReflectionClass(ExternalFailureException::class))->isFinal());
        $this->assertInstanceOf(\RuntimeException::class, $e);
        $this->assertSame(403, $e->getStatus());
        $this->assertSame('External operation answered with status 403: no right', $e->getMessage());
        $this->assertSame('External operation answered with status 500', (new ExternalFailureException(500))->getMessage());
    }
}
