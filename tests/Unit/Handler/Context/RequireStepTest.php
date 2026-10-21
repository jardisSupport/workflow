<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Tests\Unit\Handler\Context;

use JardisSupport\Workflow\Data\NodeResult;
use JardisSupport\Workflow\Exception\MissingStepException;
use JardisSupport\Workflow\Exception\WorkflowException;
use JardisSupport\Workflow\Handler\Context\RequireStep;
use PHPUnit\Framework\TestCase;

class RequireStepTest extends TestCase
{
    public function testHandsTheNodeResultBack(): void
    {
        $result = NodeResult::ok(['id' => 1]);

        $this->assertSame($result, (new RequireStep())($result, 'Place'));
    }

    public function testHandsATypedNonNodeResultObjectBackUnchanged(): void
    {
        $typed = new class {
            public function __construct(public readonly string $orderNumber = 'A-1')
            {
            }
        };

        $this->assertSame($typed, (new RequireStep())($typed, 'PlaceLimitedOrder'));
    }

    public function testNullRaisesWithTheNodeNameAsMessage(): void
    {
        try {
            (new RequireStep())(null, 'PlaceLimitedOrder');
            $this->fail('MissingStepException expected');
        } catch (MissingStepException $e) {
            $this->assertSame('PlaceLimitedOrder', $e->getMessage());
            $this->assertInstanceOf(WorkflowException::class, $e);
            $this->assertInstanceOf(\RuntimeException::class, $e);
        }
    }
}
