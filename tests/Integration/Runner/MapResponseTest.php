<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Tests\Integration\Runner;

use JardisSupport\Contract\Kernel\ResponseStatus;
use JardisSupport\Workflow\Data\Route;
use JardisSupport\Workflow\Handler\Node\MapResponse;
use JardisSupport\Workflow\Tests\Support\FakeDomainResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * MapResponse per status class: 2xx succeeds, 4xx rejects with the callee's key, the rest throws (W4).
 */
class MapResponseTest extends TestCase
{
    /**
     * @return array<string, array{int}>
     */
    public static function successStatuses(): array
    {
        return ['200' => [200], '201' => [201], '204' => [204]];
    }

    #[DataProvider('successStatuses')]
    public function testSuccessClassBecomesOkWithTheData(int $status): void
    {
        $result = (new MapResponse())(new FakeDomainResponse($status, ['id' => 7]));

        $this->assertFalse($result->isRejected());
        $this->assertSame(Route::OnTrue, $result->takenRoute());
        $this->assertSame(['id' => 7], $result->data());
    }

    /**
     * @return array<string, array{int, ResponseStatus}>
     */
    public static function rejectStatuses(): array
    {
        return [
            '400' => [400, ResponseStatus::ValidationError],
            '401' => [401, ResponseStatus::Unauthorized],
            '403' => [403, ResponseStatus::Forbidden],
            '404' => [404, ResponseStatus::NotFound],
            '409' => [409, ResponseStatus::Conflict],
            '422' => [422, ResponseStatus::RuleViolation],
        ];
    }

    #[DataProvider('rejectStatuses')]
    public function testClientErrorClassBecomesARejectWithTheCalleesKey(int $status, ResponseStatus $expected): void
    {
        $result = (new MapResponse())(new FakeDomainResponse(
            $status,
            ['messageKey' => 'order.not_found', 'orderRef' => 'A-1'],
            ['orderRef' => ['unknown', 'closed']]
        ));

        $this->assertTrue($result->isRejected());
        $this->assertSame($expected, $result->status());
        $this->assertSame('order.not_found', $result->messageKey());
        $this->assertSame(['orderRef' => 'A-1'], $result->context());
        $this->assertSame(['unknown', 'closed'], $result->errors());
        $this->assertNull($result->takenRoute());
    }

    public function testRejectWithoutAKeyFromTheCalleeHasAnEmptyKey(): void
    {
        $result = (new MapResponse())(new FakeDomainResponse(404));

        $this->assertSame('', $result->messageKey());
    }

    /**
     * @return array<string, array{int}>
     */
    public static function technicalStatuses(): array
    {
        return ['500' => [500], '503' => [503], '302' => [302], '418 (no 4xx status of the contract)' => [418]];
    }

    #[DataProvider('technicalStatuses')]
    public function testEveryOtherStatusThrows(int $status): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage((string) $status);

        (new MapResponse())(new FakeDomainResponse($status, ['messageKey' => 'ignored']));
    }
}
