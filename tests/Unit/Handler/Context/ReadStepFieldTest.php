<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Tests\Unit\Handler\Context;

use JardisSupport\Workflow\Data\NodeResult;
use JardisSupport\Workflow\Data\Route;
use JardisSupport\Workflow\Handler\Context\ReadStepField;
use PHPUnit\Framework\TestCase;

class ReadStepFieldTest extends TestCase
{
    public function testReadsAnArrayKey(): void
    {
        $this->assertSame('A-1', (new ReadStepField())(NodeResult::ok(['orderNumber' => 'A-1']), 'orderNumber'));
    }

    public function testReadsAPublicPropertyOfAnObject(): void
    {
        $data = new class {
            public string $orderNumber = 'A-2';
            private string $secret = 'x';

            public function secret(): string
            {
                return $this->secret;
            }
        };
        $read = new ReadStepField();

        $this->assertSame('A-2', $read(NodeResult::ok($data), 'orderNumber'));
        $this->assertNull($read(NodeResult::ok($data), 'secret'));
    }

    public function testMissingFieldReadsAsNull(): void
    {
        $read = new ReadStepField();

        $this->assertNull($read(NodeResult::ok(['a' => 1]), 'b'));
        $this->assertNull($read(NodeResult::ok(new \stdClass()), 'b'));
    }

    public function testNullValueReadsAsNull(): void
    {
        $this->assertNull((new ReadStepField())(NodeResult::ok(['a' => null]), 'a'));
    }

    public function testDataThatIsNeitherArrayNorObjectReadsAsNull(): void
    {
        $this->assertNull((new ReadStepField())(NodeResult::route(Route::OnTrue), 'a'));
        $this->assertNull((new ReadStepField())(NodeResult::route(Route::OnTrue, 'text'), 'a'));
    }

    public function testFalsyValuesAreKept(): void
    {
        $read = new ReadStepField();

        $this->assertSame(0, $read(NodeResult::ok(['n' => 0]), 'n'));
        $this->assertFalse($read(NodeResult::ok(['f' => false]), 'f'));
    }
}
