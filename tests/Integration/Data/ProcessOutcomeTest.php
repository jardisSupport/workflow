<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Tests\Integration\Data;

use JardisSupport\Contract\Kernel\ResponseStatus;
use JardisSupport\Workflow\Data\ProcessOutcome;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * ProcessOutcome: the success shape and the reject shape of the engine.
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
        $this->assertNull($outcome->messageKey());
        $this->assertSame([], $outcome->context());
    }

    public function testRejectCarriesMessageKeyContextAndErrorsWithoutEvents(): void
    {
        $outcome = new ProcessOutcome(
            ResponseStatus::NotFound,
            new \stdClass(),
            ['no such order'],
            [],
            'order.notFound',
            ['orderNumber' => 'ORD-404']
        );

        $this->assertSame(ResponseStatus::NotFound, $outcome->status());
        $this->assertSame(['no such order'], $outcome->errors());
        $this->assertSame([], $outcome->events());
        $this->assertSame('order.notFound', $outcome->messageKey());
        $this->assertSame(['orderNumber' => 'ORD-404'], $outcome->context());
    }

    public function testOutcomeHasNeitherCauseNorDeclaredMessageKey(): void
    {
        $methods = array_map(
            static fn(\ReflectionMethod $method): string => $method->getName(),
            (new ReflectionClass(ProcessOutcome::class))->getMethods(\ReflectionMethod::IS_PUBLIC)
        );
        sort($methods);

        $this->assertSame(
            ['__construct', 'context', 'errors', 'events', 'messageKey', 'response', 'status'],
            $methods
        );
    }
}
