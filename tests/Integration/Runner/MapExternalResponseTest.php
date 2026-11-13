<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Tests\Integration\Runner;

use JardisSupport\Contract\Kernel\ReportedFailureExceptionInterface;
use JardisSupport\Workflow\Data\Route;
use JardisSupport\Workflow\Exception\CalleeFailureException;
use JardisSupport\Workflow\Exception\ExternalFailureException;
use JardisSupport\Workflow\Handler\Node\MapExternalResponse;
use JardisSupport\Workflow\Handler\Node\MapResponse;
use JardisSupport\Workflow\Handler\Node\MapResponseInto;
use JardisSupport\Workflow\Tests\Support\FakeDomainResponse;
use JardisSupport\Workflow\Tests\Support\Into\ListReference;
use JardisSupport\Workflow\Tests\Support\Into\PlainReference;
use JardisSupport\Workflow\Tests\Support\RunnerTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * MapExternalResponse: the answer of another system. A refusal of the node-class (2xx, 400, 404, 409, 422) is
 * exactly what MapResponse answers; 401, 403, 405, a 5xx and a status outside the contract are a technical failure
 * of the other system - an ExternalFailureException without the marker "already reported", never a reject.
 */
class MapExternalResponseTest extends RunnerTestCase
{
    /**
     * @return array<string, array{int, array<string, mixed>}>
     */
    public static function statusesOfMapResponse(): array
    {
        return [
            '200' => [200, ['id' => 7]],
            '201' => [201, ['id' => 7]],
            '204' => [204, []],
            '202 (2xx outside the enum)' => [202, ['accepted' => true]],
            '400' => [400, ['@type' => 'validation', 'fields' => [['field' => 'qty']]]],
            '404' => [404, ['@type' => 'notFound', 'context' => ['sku' => 'Z-9']]],
            '409' => [409, ['reason' => 'stale', 'aggregate' => 'order', 'context' => ['a' => 1], 'violated' => []]],
            '422' => [422, ['rule' => 'InStock', 'messageKey' => 'order.noStock', 'context' => ['sku' => 'Z-9']]],
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    #[DataProvider('statusesOfMapResponse')]
    public function testEveryStatusWithANodeMeaningIsExactlyWhatMapResponseAnswers(int $status, array $data): void
    {
        $event = new \stdClass();
        $response = new FakeDomainResponse($status, $data, ['qty' => ['too low'], 'other' => ['gone']], [$event]);

        $this->assertEquals((new MapResponse())($response), (new MapExternalResponse())($response));
        $this->assertEquals(
            (new MapResponse())($response, Route::OnFalse),
            (new MapExternalResponse())($response, Route::OnFalse)
        );
    }

    /**
     * @return array<string, array{int}>
     */
    public static function technicalStatuses(): array
    {
        return [
            '401' => [401],
            '403' => [403],
            '405' => [405],
            '500' => [500],
            '502' => [502],
            '599' => [599],
            '302 (outside the contract)' => [302],
            '418 (outside the contract)' => [418],
            '100 (outside the contract)' => [100],
        ];
    }

    #[DataProvider('technicalStatuses')]
    public function testATechnicalFailureOfTheOtherSystemThrowsWithStatusAndReason(int $status): void
    {
        $response = new FakeDomainResponse($status, ['messageKey' => 'auth.denied'], ['token' => ['expired']]);

        try {
            (new MapExternalResponse())($response);
            $this->fail('A technical failure must throw');
        } catch (ExternalFailureException $thrown) {
            $this->assertSame($status, $thrown->getStatus());
            $this->assertStringContainsString((string) $status, $thrown->getMessage());
            $this->assertStringContainsString('expired', $thrown->getMessage());
        }
    }

    public function testForbiddenIsATechnicalFailureNeverARefusal(): void
    {
        $this->expectException(ExternalFailureException::class);
        $this->expectExceptionMessage('External operation answered with status 403: not allowed');

        (new MapExternalResponse())(new FakeDomainResponse(403, [], ['message' => ['not allowed']]));
    }

    #[DataProvider('technicalStatuses')]
    public function testTheExceptionDoesNotCarryTheMarkerAlreadyReported(int $status): void
    {
        try {
            (new MapExternalResponse())(new FakeDomainResponse($status));
            $this->fail('A technical failure must throw');
        } catch (\Throwable $thrown) {
            $this->assertInstanceOf(ExternalFailureException::class, $thrown);
            $this->assertNotInstanceOf(ReportedFailureExceptionInterface::class, $thrown);
            $this->assertNotInstanceOf(CalleeFailureException::class, $thrown);
            $this->assertInstanceOf(\RuntimeException::class, $thrown);
        }
    }

    #[DataProvider('technicalStatuses')]
    public function testOnRejectNeverActsOnATechnicalFailure(int $status): void
    {
        $this->expectException(ExternalFailureException::class);

        (new MapExternalResponse())(new FakeDomainResponse($status), Route::OnFalse);
    }

    public function testTheReasonIsTakenFromAnErrorsWithoutListForm(): void
    {
        try {
            (new MapExternalResponse())(new FakeDomainResponse(401, [], ['message' => 'token missing']));
            $this->fail('A 401 must throw');
        } catch (ExternalFailureException $thrown) {
            $this->assertSame('External operation answered with status 401: token missing', $thrown->getMessage());
        }
    }

    public function testWithoutAnyErrorsTheMessageNamesOnlyTheStatus(): void
    {
        try {
            (new MapExternalResponse())(new FakeDomainResponse(503));
            $this->fail('A 5xx must throw');
        } catch (ExternalFailureException $thrown) {
            $this->assertSame('External operation answered with status 503', $thrown->getMessage());
        }
    }

    public function testAListFieldRunsThroughTheTypedMapping(): void
    {
        $items = [['sku' => 'A-1', 'qty' => 2], ['sku' => 'B-2', 'qty' => 1]];

        $result = (new MapResponseInto(new MapExternalResponse()))(
            new FakeDomainResponse(200, ['@type' => 'list', 'reference' => 'R-9', 'items' => $items]),
            ListReference::class
        );

        $data = $result->data();
        $this->assertInstanceOf(ListReference::class, $data);
        $this->assertSame('R-9', $data->reference);
        $this->assertSame($items, $data->items);
    }

    public function testTheTypedMappingRefusesAForeignRefusalLikeMapResponse(): void
    {
        $response = new FakeDomainResponse(404, ['@type' => 'notFound', 'context' => ['sku' => 'Z-9']]);

        $this->assertEquals(
            (new MapResponseInto())($response, PlainReference::class),
            (new MapResponseInto(new MapExternalResponse()))($response, PlainReference::class)
        );
    }

    #[DataProvider('technicalStatuses')]
    public function testTheTypedMappingThrowsOnATechnicalFailureOfTheOtherSystem(int $status): void
    {
        $this->expectException(ExternalFailureException::class);

        (new MapResponseInto(new MapExternalResponse()))(new FakeDomainResponse($status), PlainReference::class);
    }
}
