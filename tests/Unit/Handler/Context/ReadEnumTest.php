<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Tests\Unit\Handler\Context;

use JardisSupport\Workflow\Handler\Context\ReadEnum;
use JardisSupport\Workflow\Tests\Support\Suit;
use PHPUnit\Framework\TestCase;

class ReadEnumTest extends TestCase
{
    public function testValidValueBecomesTheCase(): void
    {
        $this->assertSame(Suit::Spades, (new ReadEnum())('s', Suit::class));
    }

    public function testInvalidValueKeepsTheValueError(): void
    {
        $this->expectException(\ValueError::class);

        (new ReadEnum())('x', Suit::class);
    }

    public function testNullStaysNull(): void
    {
        $this->assertNull((new ReadEnum())(null, Suit::class));
    }
}
