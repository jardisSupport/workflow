<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Tests\Integration\Data;

use JardisSupport\Contract\Kernel\ResponseStatus;
use JardisSupport\Workflow\Data\NodeResult;
use JardisSupport\Workflow\Data\Route;
use JardisSupport\Workflow\Exception\ConstructionException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * NodeResult: factories, reject contract, event wither and immutability.
 */
class NodeResultTest extends TestCase
{
    public function testClassIsFinalAndReadonly(): void
    {
        $reflection = new ReflectionClass(NodeResult::class);

        $this->assertTrue($reflection->isFinal());
        $this->assertTrue($reflection->isReadOnly());
    }

    public function testOkRoutesOnTrueAndCarriesArrayData(): void
    {
        $result = NodeResult::ok(['id' => 7]);

        $this->assertSame(Route::OnTrue, $result->takenRoute());
        $this->assertSame(['id' => 7], $result->data());
        $this->assertFalse($result->isRejected());
        $this->assertNull($result->status());
        $this->assertSame([], $result->events());
    }

    public function testOkCarriesObjectData(): void
    {
        $data = new \stdClass();

        $this->assertSame($data, NodeResult::ok($data)->data());
    }

    public function testRouteTakesTheGivenRouteAndData(): void
    {
        $result = NodeResult::route(Route::OnSkip, 'payload');

        $this->assertSame(Route::OnSkip, $result->takenRoute());
        $this->assertSame('payload', $result->data());
        $this->assertFalse($result->isRejected());
    }

    public function testRouteDataDefaultsToNull(): void
    {
        $this->assertNull(NodeResult::route(Route::OnExit)->data());
    }

    public function testRejectCarriesStatusKeyContextAndErrors(): void
    {
        $result = NodeResult::reject(
            ResponseStatus::Conflict,
            'order.alreadyShipped',
            ['orderNo' => 'A-1'],
            ['already shipped']
        );

        $this->assertTrue($result->isRejected());
        $this->assertNull($result->takenRoute());
        $this->assertSame(ResponseStatus::Conflict, $result->status());
        $this->assertSame('order.alreadyShipped', $result->messageKey());
        $this->assertSame(['orderNo' => 'A-1'], $result->context());
        $this->assertSame(['already shipped'], $result->errors());
    }

    public function testRejectContextAndErrorsDefaultToEmpty(): void
    {
        $result = NodeResult::reject(ResponseStatus::NotFound, 'x.notFound');

        $this->assertSame([], $result->context());
        $this->assertSame([], $result->errors());
    }

    #[DataProvider('nonClientErrorStatuses')]
    public function testRejectWithNon4xxStatusFailsConstruction(ResponseStatus $status): void
    {
        $this->expectException(ConstructionException::class);

        NodeResult::reject($status, 'x.key');
    }

    /**
     * @return array<string, array{ResponseStatus}>
     */
    public static function nonClientErrorStatuses(): array
    {
        return [
            'success' => [ResponseStatus::Success],
            'created' => [ResponseStatus::Created],
            'no content' => [ResponseStatus::NoContent],
            'internal error' => [ResponseStatus::InternalError],
        ];
    }

    public function testRejectAcceptsEveryClientErrorStatus(): void
    {
        foreach (ResponseStatus::cases() as $status) {
            if ($status->value >= 400 && $status->value < 500) {
                $this->assertSame($status, NodeResult::reject($status, 'k')->status());
            }
        }
    }

    public function testWithEventsReturnsNewInstanceAndLeavesOriginalUntouched(): void
    {
        $original = NodeResult::ok(['a' => 1]);
        $event = new \stdClass();

        $withEvents = $original->withEvents($event);

        $this->assertNotSame($original, $withEvents);
        $this->assertSame([], $original->events());
        $this->assertSame([$event], $withEvents->events());
        $this->assertSame(Route::OnTrue, $withEvents->takenRoute());
        $this->assertSame(['a' => 1], $withEvents->data());
    }

    public function testWithEventsReplacesEarlierEvents(): void
    {
        $first = new \stdClass();
        $second = new \stdClass();

        $result = NodeResult::ok([])->withEvents($first)->withEvents($second);

        $this->assertSame([$second], $result->events());
    }

    public function testWithEventsKeepsRejectIntact(): void
    {
        $result = NodeResult::reject(ResponseStatus::RuleViolation, 'r.violated', ['f' => 1])
            ->withEvents(new \stdClass());

        $this->assertTrue($result->isRejected());
        $this->assertSame('r.violated', $result->messageKey());
        $this->assertSame(['f' => 1], $result->context());
    }

    public function testEveryPropertyIsReadonlyAndNoSetterExists(): void
    {
        $reflection = new ReflectionClass(NodeResult::class);

        foreach ($reflection->getProperties() as $property) {
            $this->assertTrue($property->isReadOnly(), $property->getName());
        }
        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            $this->assertDoesNotMatchRegularExpression('/^(set|add|append)/', $method->getName());
        }
    }
}
