<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Tests\Integration\Data;

use JardisSupport\Workflow\Data\ReadResult;
use JardisSupport\Workflow\Exception\ConstructionException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * ReadResult<T>: list form with total, single form found/empty.
 */
class ReadResultTest extends TestCase
{
    public function testClassIsFinalAndReadonly(): void
    {
        $reflection = new ReflectionClass(ReadResult::class);

        $this->assertTrue($reflection->isFinal());
        $this->assertTrue($reflection->isReadOnly());
    }

    public function testOfListKeepsItemsAndTotalIndependently(): void
    {
        $a = new \stdClass();
        $b = new \stdClass();

        $result = ReadResult::ofList([$a, $b], 42);

        $this->assertSame([$a, $b], $result->items());
        $this->assertSame(42, $result->total());
        $this->assertSame($a, $result->first());
        $this->assertFalse($result->isEmpty());
    }

    public function testOfListEmptyIsEmptyWithItsTotal(): void
    {
        $result = ReadResult::ofList([], 0);

        $this->assertSame([], $result->items());
        $this->assertSame(0, $result->total());
        $this->assertNull($result->first());
        $this->assertTrue($result->isEmpty());
    }

    public function testOfListWithTotalBelowItemCountFailsConstruction(): void
    {
        $this->expectException(ConstructionException::class);

        ReadResult::ofList(['a', 'b'], 1);
    }

    public function testOfOneFoundIsAListOfOne(): void
    {
        $item = new \stdClass();

        $result = ReadResult::ofOne($item);

        $this->assertSame([$item], $result->items());
        $this->assertSame(1, $result->total());
        $this->assertSame($item, $result->first());
        $this->assertFalse($result->isEmpty());
    }

    public function testOfOneNullIsEmpty(): void
    {
        $result = ReadResult::ofOne(null);

        $this->assertSame([], $result->items());
        $this->assertSame(0, $result->total());
        $this->assertNull($result->first());
        $this->assertTrue($result->isEmpty());
    }
}
