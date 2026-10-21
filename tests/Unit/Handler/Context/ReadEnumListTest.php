<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Tests\Unit\Handler\Context;

use JardisSupport\Workflow\Handler\Context\ReadEnumList;
use JardisSupport\Workflow\Tests\Support\Suit;
use PHPUnit\Framework\TestCase;

class ReadEnumListTest extends TestCase
{
    public function testListBecomesAListOfCases(): void
    {
        $this->assertSame([Suit::Hearts, Suit::Spades], (new ReadEnumList())(['h', 's'], Suit::class));
    }

    public function testEmptyListStaysEmpty(): void
    {
        $this->assertSame([], (new ReadEnumList())([], Suit::class));
    }

    public function testNullStaysNull(): void
    {
        $this->assertNull((new ReadEnumList())(null, Suit::class));
    }

    public function testKeysAreDroppedToAList(): void
    {
        $this->assertSame([Suit::Hearts], (new ReadEnumList())(['k' => 'h'], Suit::class));
    }

    public function testInvalidElementKeepsTheValueError(): void
    {
        $this->expectException(\ValueError::class);

        (new ReadEnumList())(['h', 'x'], Suit::class);
    }
}
