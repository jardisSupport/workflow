<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Tests\Unit\Handler\Context;

use JardisSupport\Workflow\Data\NodeResult;
use JardisSupport\Workflow\Exception\MissingStepException;
use JardisSupport\Workflow\Handler\Context\ReadStepField;
use JardisSupport\Workflow\Handler\Context\RequireStepField;
use PHPUnit\Framework\TestCase;

class RequireStepFieldTest extends TestCase
{
    public function testReturnsTheValueFromAnArray(): void
    {
        $this->assertSame(
            'A-1',
            (new RequireStepField())(NodeResult::ok(['orderNumber' => 'A-1']), 'orderNumber', 'PlaceLimitedOrder')
        );
    }

    public function testReturnsTheValueFromAnObject(): void
    {
        $data = (object) ['orderNumber' => 'A-2'];

        $this->assertSame('A-2', (new RequireStepField())(NodeResult::ok($data), 'orderNumber', 'Place'));
    }

    public function testKeepsFalsyValues(): void
    {
        $this->assertSame(0, (new RequireStepField())(NodeResult::ok(['n' => 0]), 'n', 'Place'));
    }

    public function testMissingFieldRaisesWithNodeSlashField(): void
    {
        try {
            (new RequireStepField())(NodeResult::ok(['a' => 1]), 'orderNumber', 'PlaceLimitedOrder');
            $this->fail('MissingStepException expected');
        } catch (MissingStepException $e) {
            $this->assertSame('PlaceLimitedOrder/orderNumber', $e->getMessage());
        }
    }

    public function testNullValueRaises(): void
    {
        $this->expectException(MissingStepException::class);
        $this->expectExceptionMessage('Place/a');

        (new RequireStepField())(NodeResult::ok(['a' => null]), 'a', 'Place');
    }

    public function testAcceptsAnInjectedReader(): void
    {
        $this->assertSame(
            'v',
            (new RequireStepField(new ReadStepField()))(NodeResult::ok(['k' => 'v']), 'k', 'N')
        );
    }
}
