<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Tests\Unit;

use JardisSupport\Workflow\WorkflowResult;
use PHPUnit\Framework\TestCase;

/**
 * Unit Tests for WorkflowResult
 *
 * Tests the WorkflowResult value object for workflow transition control
 * after the contract alignment: only seven named ON_ statuses, no
 * STATUS_ / transition-split API.
 */
class WorkflowResultTest extends TestCase
{
    /**
     * @dataProvider validStatusProvider
     */
    public function testConstructorAcceptsSevenValidConstants(string $status): void
    {
        $result = new WorkflowResult($status);

        $this->assertSame($status, $result->getStatus());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function validStatusProvider(): array
    {
        return [
            'onSuccess' => [WorkflowResult::ON_SUCCESS],
            'onFail'    => [WorkflowResult::ON_FAIL],
            'onTimeout' => [WorkflowResult::ON_TIMEOUT],
            'onSkip'    => [WorkflowResult::ON_SKIP],
            'onCancel'  => [WorkflowResult::ON_CANCEL],
            'onEvent'   => [WorkflowResult::ON_EVENT],
            'onExit'    => [WorkflowResult::ON_EXIT],
        ];
    }

    public function testConstructorRejectsInvalidStatus(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid workflow status "foo"');

        new WorkflowResult('foo');
    }

    public function testGetStatusReturnsConstructorValue(): void
    {
        $result = new WorkflowResult(WorkflowResult::ON_SUCCESS);

        $this->assertSame(WorkflowResult::ON_SUCCESS, $result->getStatus());
    }

    /**
     * @dataProvider dataProvider
     */
    public function testGetDataReturnsConstructorValue(mixed $data): void
    {
        $result = new WorkflowResult(WorkflowResult::ON_SUCCESS, $data);

        $this->assertSame($data, $result->getData());
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function dataProvider(): array
    {
        $object = new \stdClass();
        $object->id = 1;

        return [
            'null'   => [null],
            'int'    => [42],
            'string' => ['abc'],
            'array'  => [['k' => 'v']],
            'object' => [$object],
        ];
    }

    public function testGetHandlerFqcnReturnsNullByDefault(): void
    {
        $result = new WorkflowResult(WorkflowResult::ON_SUCCESS);

        $this->assertNull($result->getHandlerFqcn());
    }

    public function testWithHandlerStampsImmutably(): void
    {
        $original = new WorkflowResult(WorkflowResult::ON_SUCCESS, ['x' => 1]);

        $stamped = $original->withHandler('App\\Handler\\X');

        $this->assertNotSame($original, $stamped);
        $this->assertNull($original->getHandlerFqcn());
        $this->assertSame('App\\Handler\\X', $stamped->getHandlerFqcn());
        $this->assertSame(WorkflowResult::ON_SUCCESS, $stamped->getStatus());
        $this->assertSame(['x' => 1], $stamped->getData());
    }

    public function testConstantStringValues(): void
    {
        $this->assertSame('onSuccess', WorkflowResult::ON_SUCCESS);
        $this->assertSame('onFail', WorkflowResult::ON_FAIL);
        $this->assertSame('onTimeout', WorkflowResult::ON_TIMEOUT);
        $this->assertSame('onSkip', WorkflowResult::ON_SKIP);
        $this->assertSame('onCancel', WorkflowResult::ON_CANCEL);
        $this->assertSame('onEvent', WorkflowResult::ON_EVENT);
        $this->assertSame('onExit', WorkflowResult::ON_EXIT);
    }
}
