<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Tests\Integration\Data;

use JardisSupport\Workflow\Data\Reference;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Reference: root identifier plus child references.
 */
class ReferenceTest extends TestCase
{
    public function testClassIsFinalAndReadonly(): void
    {
        $reflection = new ReflectionClass(Reference::class);

        $this->assertTrue($reflection->isFinal());
        $this->assertTrue($reflection->isReadOnly());
    }

    public function testCarriesRootIdentifierAndChildReferences(): void
    {
        $reference = new Reference('ORD-1', ['line' => ['L-1', 'L-2'], 'note' => [5]]);

        $this->assertSame('ORD-1', $reference->root());
        $this->assertSame(['line' => ['L-1', 'L-2'], 'note' => [5]], $reference->children());
    }

    public function testWithoutChildrenHasEmptyChildList(): void
    {
        $reference = new Reference(17);

        $this->assertSame(17, $reference->root());
        $this->assertSame([], $reference->children());
    }
}
