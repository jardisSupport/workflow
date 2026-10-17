<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Tests\Integration\Data;

use JardisSupport\Contract\Kernel\ResponseStatus;
use JardisSupport\Workflow\Data\Failure\NotFound;
use JardisSupport\Workflow\Data\ProcessOutcome;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * ProcessOutcome: success, reject and thrown-failure shapes.
 */
class ProcessOutcomeTest extends TestCase
{
    public function testClassIsFinalAndReadonly(): void
    {
        $reflection = new ReflectionClass(ProcessOutcome::class);

        $this->assertTrue($reflection->isFinal());
        $this->assertTrue($reflection->isReadOnly());
    }

    public function testSuccessCarriesResponseAndEvents(): void
    {
        $response = new \stdClass();
        $event = new \stdClass();

        $outcome = new ProcessOutcome(ResponseStatus::Success, $response, [], [$event]);

        $this->assertSame(ResponseStatus::Success, $outcome->status());
        $this->assertSame($response, $outcome->response());
        $this->assertSame([], $outcome->errors());
        $this->assertSame([$event], $outcome->events());
        $this->assertNull($outcome->cause());
    }

    public function testRejectCarriesFailureFormAndErrorsWithoutEvents(): void
    {
        $failure = new NotFound('order.notFound');

        $outcome = new ProcessOutcome(ResponseStatus::NotFound, $failure, ['no such order']);

        $this->assertSame(ResponseStatus::NotFound, $outcome->status());
        $this->assertSame($failure, $outcome->response());
        $this->assertSame(['no such order'], $outcome->errors());
        $this->assertSame([], $outcome->events());
        $this->assertNull($outcome->cause());
    }

    public function testThrowCarriesTheCause(): void
    {
        $cause = new \RuntimeException('boom');

        $outcome = new ProcessOutcome(ResponseStatus::InternalError, new \stdClass(), [], [], $cause);

        $this->assertSame(ResponseStatus::InternalError, $outcome->status());
        $this->assertSame($cause, $outcome->cause());
    }
}
