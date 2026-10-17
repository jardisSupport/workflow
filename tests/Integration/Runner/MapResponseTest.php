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
 * MapResponse per status class: 2xx succeeds, 4xx rejects with the callee's plain context, the rest throws (W4).
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
     * The callee's data per status in the form its own envelope has, and what the reject carries.
     *
     * @return array<string, array{ResponseStatus, array<string, mixed>, string, array<string, mixed>}>
     */
    public static function rejectForms(): array
    {
        return [
            '400 validation: fields only, the key stays unnamed' => [
                ResponseStatus::ValidationError,
                ['@type' => 'validation', 'messageKey' => 'order.invalid', 'fields' => [['field' => 'qty']]],
                '',
                ['fields' => [['field' => 'qty']]],
            ],
            '404 notFound: the inner context is the plain context' => [
                ResponseStatus::NotFound,
                ['@type' => 'notFound', 'messageKey' => 'order.gone', 'context' => ['orderRef' => 'A-1']],
                '',
                ['orderRef' => 'A-1'],
            ],
            '409 conflict: reason, aggregate, violated beside the plain context' => [
                ResponseStatus::Conflict,
                [
                    '@type' => 'concurrencyConflict',
                    'reason' => 'stale',
                    'aggregate' => 'order',
                    'context' => ['orderRef' => 'A-1'],
                    'violated' => ['uq_order'],
                ],
                '',
                ['reason' => 'stale', 'aggregate' => 'order', 'violated' => ['uq_order'], 'orderRef' => 'A-1'],
            ],
            '422 ruleViolation: the rule and the callee key, the plain context' => [
                ResponseStatus::RuleViolation,
                [
                    '@type' => 'ruleViolation',
                    'rule' => 'OrderMustBeOpen',
                    'messageKey' => 'order.rule.open',
                    'context' => ['orderRef' => 'A-1'],
                ],
                'order.rule.open',
                ['rule' => 'OrderMustBeOpen', 'orderRef' => 'A-1'],
            ],
            '403 without a form: the data passes through unchanged, its key stays inside' => [
                ResponseStatus::Forbidden,
                ['messageKey' => 'auth.denied', 'scope' => 'orders'],
                '',
                ['messageKey' => 'auth.denied', 'scope' => 'orders'],
            ],
            '401 without a key' => [ResponseStatus::Unauthorized, ['scope' => 'orders'], '', ['scope' => 'orders']],
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $context
     */
    #[DataProvider('rejectForms')]
    public function testClientErrorClassBecomesARejectWithThePlainContext(
        ResponseStatus $expected,
        array $data,
        string $key,
        array $context
    ): void {
        $result = (new MapResponse())(new FakeDomainResponse(
            $expected->value,
            $data,
            ['orderRef' => ['unknown', 'closed'], 'other' => ['gone']]
        ));

        $this->assertTrue($result->isRejected());
        $this->assertSame($expected, $result->status());
        $this->assertSame($key, $result->messageKey());
        $this->assertSame($context, $result->context());
        $this->assertSame(['unknown', 'closed', 'gone'], $result->errors());
        $this->assertNull($result->takenRoute());
    }

    public function testAnEmptyContextOfTheCalleeArrivesAsAnObjectAndStaysEmpty(): void
    {
        $result = (new MapResponse())(new FakeDomainResponse(404, ['@type' => 'notFound', 'context' => new \stdClass()]));

        $this->assertSame([], $result->context());
    }

    public function testTheFormsOwnFieldsWinOverAnInnerContextKeyOfTheSameName(): void
    {
        $result = (new MapResponse())(new FakeDomainResponse(409, [
            'reason' => 'stale',
            'aggregate' => 'order',
            'context' => ['reason' => 'inner', 'orderRef' => 'A-1'],
            'violated' => [],
        ]));

        $this->assertSame('stale', $result->context()['reason']);
        $this->assertSame('A-1', $result->context()['orderRef']);
    }

    public function testRejectWithoutAKeyFromTheCalleeHasAnEmptyKey(): void
    {
        $result = (new MapResponse())(new FakeDomainResponse(403));

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
