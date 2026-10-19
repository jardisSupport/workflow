<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Tests\Integration\Runner;

use JardisSupport\Contract\Kernel\ResponseStatus;
use JardisSupport\Workflow\Data\NodeResult;
use JardisSupport\Workflow\Data\ProcessContext;
use JardisSupport\Workflow\Data\ProcessDefinition;
use JardisSupport\Workflow\Data\Route;
use JardisSupport\Workflow\Handler\Node\MapResponse;
use JardisSupport\Workflow\Tests\Support\FakeDomainResponse;
use JardisSupport\Workflow\Tests\Support\RunnerTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * MapResponse per status class: 2xx succeeds with the callee's data and events, 4xx rejects with the callee's plain
 * context - or routes on a dev decision -, the rest throws (W4). Also the mapping of a sub-process (the inner door's answer).
 */
class MapResponseTest extends RunnerTestCase
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

    public function testPassesInnerEventsThrough(): void
    {
        $first = new \stdClass();
        $second = new \stdClass();

        $result = (new MapResponse())(new FakeDomainResponse(200, ['id' => 7], [], [$first, $second]));

        $this->assertFalse($result->isRejected());
        $this->assertSame([$first, $second], $result->events());
        $this->assertSame(['id' => 7], $result->data());
    }

    /**
     * @return array<string, array{int}>
     */
    public static function clientErrors(): array
    {
        return ['400' => [400], '401' => [401], '403' => [403], '404' => [404], '409' => [409], '422' => [422]];
    }

    #[DataProvider('clientErrors')]
    public function testOnRejectRouteCarriesOnInsteadOfRefusing(int $status): void
    {
        $result = (new MapResponse())(new FakeDomainResponse($status, ['why' => 'x']), Route::OnFalse);

        $this->assertFalse($result->isRejected());
        $this->assertSame(Route::OnFalse, $result->takenRoute());
    }

    #[DataProvider('technicalStatuses')]
    public function testATechnicalStatusIsNeverRoutedAwayByOnReject(int $status): void
    {
        $this->expectException(\RuntimeException::class);

        (new MapResponse())(new FakeDomainResponse($status), Route::OnFalse);
    }

    /**
     * @return array<string, array{ResponseStatus, string, array<string, mixed>}>
     */
    public static function innerRejects(): array
    {
        return [
            '400' => [ResponseStatus::ValidationError, 'raw.invalid', ['fields' => [['field' => 'qty']]]],
            '404' => [ResponseStatus::NotFound, 'raw.gone', ['sku' => 'Z-9']],
            '409' => [ResponseStatus::Conflict, 'raw.conflict', ['reason' => 'stale', 'aggregate' => 'Order', 'violated' => ['uq_no']]],
            '422' => [ResponseStatus::RuleViolation, 'order.noStock', ['rule' => 'InStock']],
            '403' => [ResponseStatus::Forbidden, 'raw.forbidden', ['why' => 'owner']],
        ];
    }

    /**
     * @param array<string, mixed> $context
     */
    #[DataProvider('innerRejects')]
    public function testInnerRejectBecomesOuterRejectWithTheSameStatus(
        ResponseStatus $status,
        string $key,
        array $context
    ): void {
        $runner = $this->runner();
        $inner = ProcessDefinition::for('In', 'Inner')->node('Inner\\A')->terminal()->build();
        $outer = ProcessDefinition::for('In', 'Outer')->node('Outer\\A')->terminal()->build();
        $innerNodes = $this->factory(['Inner\\A' => static fn(ProcessContext $c): NodeResult => NodeResult::reject(
            $status,
            $key,
            $context,
            ['inner said no']
        )]);

        $innerOutcome = $runner->run($inner, new \stdClass(), null, $innerNodes);
        $outerOutcome = $runner->run($outer, new \stdClass(), null, $this->factory([
            'Outer\\A' => $this->subProcess($runner, $inner, $innerNodes),
        ]));

        $this->assertSame($status, $innerOutcome->status());
        $this->assertSame($status, $outerOutcome->status(), 'the outer answer has the status of the inner one');
        $this->assertSame($innerOutcome->context(), $outerOutcome->context());
        $this->assertSame(['inner said no'], $outerOutcome->errors());
    }
}
