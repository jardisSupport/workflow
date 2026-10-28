<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Tests\Integration\Runner;

use JardisSupport\Contract\Kernel\ResponseStatus;
use JardisSupport\Workflow\Data\ProcessContext;
use JardisSupport\Workflow\Data\ProcessDefinition;
use JardisSupport\Workflow\Data\Route;
use JardisSupport\Workflow\Exception\CalleeFailureException;
use JardisSupport\Workflow\Exception\ConstructionException;
use JardisSupport\Workflow\Handler\Node\MapResponse;
use JardisSupport\Workflow\Handler\Node\MapResponseInto;
use JardisSupport\Workflow\Tests\Support\FakeDomainResponse;
use JardisSupport\Workflow\Tests\Support\Into\BuiltReference;
use JardisSupport\Workflow\Tests\Support\Into\FrozenReference;
use JardisSupport\Workflow\Tests\Support\Into\PlainReference;
use JardisSupport\Workflow\Tests\Support\Into\PlainReferenceWithDefault;
use JardisSupport\Workflow\Tests\Support\RunnerTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * MapResponseInto: the status mapping of MapResponse, and the 2xx data as an object of the given class.
 */
class MapResponseIntoTest extends RunnerTestCase
{
    public function testWithAConstructorTheObjectIsBuiltThroughIt(): void
    {
        $result = (new MapResponseInto())(
            new FakeDomainResponse(201, ['@type' => 'x', 'id' => 5, 'reference' => 'R-1', 'count' => 4]),
            BuiltReference::class
        );

        $this->assertSame(Route::OnTrue, $result->takenRoute());
        $this->assertEquals(new BuiltReference('R-1', 4), $result->data());
    }

    public function testWithoutAConstructorThePublicPropertiesAreFilled(): void
    {
        $result = (new MapResponseInto())(
            new FakeDomainResponse(200, ['reference' => 'R-2', 'count' => 7]),
            PlainReference::class
        );

        $data = $result->data();
        $this->assertInstanceOf(PlainReference::class, $data);
        $this->assertSame('R-2', $data->reference);
        $this->assertSame(7, $data->count);
    }

    public function testExtraKeysAtTypeAndIdFallAwayWithoutAConstructor(): void
    {
        $result = (new MapResponseInto())(
            new FakeDomainResponse(200, ['@type' => 'created', 'id' => 99, 'reference' => 'R-3', 'count' => 1]),
            PlainReference::class
        );

        $this->assertEquals(['reference' => 'R-3', 'count' => 1], get_object_vars($result->data()));
    }

    public function testAPropertyWithADefaultNeedsNoValue(): void
    {
        $result = (new MapResponseInto())(
            new FakeDomainResponse(200, ['reference' => 'R-4']),
            PlainReferenceWithDefault::class
        );

        $this->assertEquals(['reference' => 'R-4', 'note' => 'none'], get_object_vars($result->data()));
    }

    public function testAMissingKeyWithoutAConstructorIsAConstructionException(): void
    {
        $this->expectException(ConstructionException::class);
        $this->expectExceptionMessage('needs a value for the required field "count"');

        (new MapResponseInto())(new FakeDomainResponse(200, ['reference' => 'R-5']), PlainReference::class);
    }

    public function testAMissingKeyWithAConstructorIsAConstructionException(): void
    {
        $this->expectException(ConstructionException::class);
        $this->expectExceptionMessage('needs a value for the required field "reference"');

        (new MapResponseInto())(new FakeDomainResponse(200, ['count' => 2]), BuiltReference::class);
    }

    public function testAValueOfTheWrongTypeWithoutAConstructorIsAConstructionException(): void
    {
        $this->expectException(ConstructionException::class);

        (new MapResponseInto())(
            new FakeDomainResponse(200, ['reference' => 'R-6', 'count' => 'many']),
            PlainReference::class
        );
    }

    public function testAValueOfTheWrongTypeWithAConstructorIsAConstructionException(): void
    {
        $this->expectException(ConstructionException::class);

        (new MapResponseInto())(
            new FakeDomainResponse(200, ['reference' => 'R-6', 'count' => 'many']),
            BuiltReference::class
        );
    }

    public function testAReadonlyTargetWithoutAConstructorIsAConstructionException(): void
    {
        $this->expectException(ConstructionException::class);
        $this->expectExceptionMessage('readonly');

        (new MapResponseInto())(new FakeDomainResponse(200, ['reference' => 'R-7']), FrozenReference::class);
    }

    public function testAnUnknownClassIsAConstructionException(): void
    {
        $this->expectException(ConstructionException::class);
        $this->expectExceptionMessage('does not exist');

        (new MapResponseInto())(new FakeDomainResponse(200, []), 'No\\Such\\Reference');
    }

    public function testTheDomainEventsStayAtTheResultAndInternalOnesStayBehind(): void
    {
        $domain = new \stdClass();
        $internal = new \stdClass();

        $result = (new MapResponseInto())(
            new FakeDomainResponse(201, ['reference' => 'R-8', 'count' => 1], [], [$domain], [$internal]),
            PlainReference::class
        );

        $this->assertSame([$domain], $result->events());
    }

    public function testOnRejectTrueRoutesA4xxInsteadOfBuildingTheObject(): void
    {
        $result = (new MapResponseInto())(
            new FakeDomainResponse(422, ['messageKey' => 'p.refused']),
            PlainReference::class,
            Route::OnTrue
        );

        $this->assertFalse($result->isRejected());
        $this->assertSame(Route::OnTrue, $result->takenRoute());
        $this->assertNull($result->data());
    }

    public function testOnRejectFalseRoutesA4xx(): void
    {
        $result = (new MapResponseInto())(
            new FakeDomainResponse(404, ['messageKey' => 'p.gone']),
            PlainReference::class,
            Route::OnFalse
        );

        $this->assertSame(Route::OnFalse, $result->takenRoute());
    }

    /**
     * @return array<string, array{int, array<string, mixed>}>
     */
    public static function rejectStatuses(): array
    {
        return [
            '400' => [400, ['@type' => 'validation', 'fields' => [['field' => 'qty']]]],
            '404' => [404, ['@type' => 'notFound', 'context' => ['orderRef' => 'A-1']]],
            '409' => [409, ['@type' => 'concurrencyConflict', 'reason' => 'stale', 'context' => []]],
            '422' => [422, ['@type' => 'ruleViolation', 'rule' => 'R', 'messageKey' => 'p.refused', 'context' => []]],
            '403' => [403, ['reason' => 'denied']],
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    #[DataProvider('rejectStatuses')]
    public function testA4xxRejectsExactlyLikeMapResponse(int $status, array $data): void
    {
        $response = new FakeDomainResponse($status, $data, ['field' => ['broken']]);

        $into = (new MapResponseInto())($response, PlainReference::class);
        $plain = (new MapResponse())($response);

        $this->assertEquals($plain, $into);
        $this->assertTrue($into->isRejected());
    }

    public function testA5xxStillThrowsTheCalleeFailure(): void
    {
        $this->expectException(CalleeFailureException::class);

        (new MapResponseInto())(new FakeDomainResponse(503), PlainReference::class, Route::OnTrue);
    }

    public function testAnInjectedMapResponseIsTheOneMapping(): void
    {
        $result = (new MapResponseInto(new MapResponse()))(
            new FakeDomainResponse(200, ['reference' => 'R-9', 'count' => 2]),
            BuiltReference::class
        );

        $this->assertEquals(new BuiltReference('R-9', 2), $result->data());
    }

    public function testInsideARunTheNextNodeReadsTheTypedReference(): void
    {
        $first = 'Flow\\Call';
        $second = 'Flow\\Read';
        $definition = ProcessDefinition::for('In', 'P')
            ->node($first)->onTrue($second)
            ->node($second)->terminal()
            ->build();

        $outcome = $this->runner()->run($definition, new \stdClass(), $this->factory([
            $first => static fn(ProcessContext $c) => (new MapResponseInto())(
                new FakeDomainResponse(201, ['id' => 3, 'reference' => 'R-10', 'count' => 2]),
                PlainReference::class
            ),
            $second => static fn(ProcessContext $c) => \JardisSupport\Workflow\Data\NodeResult::ok(
                ['seen' => $c->latest($first)?->data()->reference]
            ),
        ]));

        $this->assertSame(ResponseStatus::Success, $outcome->status());
        $this->assertEquals((object) ['seen' => 'R-10'], $outcome->response());
    }
}
