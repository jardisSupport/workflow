<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Tests\Integration\Response;

use JardisSupport\Contract\Kernel\ResponseStatus;
use JardisSupport\Workflow\Data\Failure\Conflict;
use JardisSupport\Workflow\Data\Failure\NotFound;
use JardisSupport\Workflow\Data\Failure\RuleViolation;
use JardisSupport\Workflow\Data\Failure\ValidationFailure;
use JardisSupport\Workflow\Data\NodeResult;
use JardisSupport\Workflow\Data\ProcessContext;
use JardisSupport\Workflow\Data\ProcessDefinition;
use JardisSupport\Workflow\Data\ProcessOutcome;
use JardisSupport\Workflow\Handler\Response\BuildResponseData;
use JardisSupport\Workflow\Tests\Support\Process\Checkout\Response\ReserveStockResponse;
use JardisSupport\Workflow\Tests\Support\RunnerTestCase;
use stdClass;

/**
 * `data` of the envelope per answer form, from the outcome of a real run. The forms are the ones
 * of the pre-move generated payload closures (builder commit e72b2b528, see EnvelopeParityTest);
 * the byte parity of the whole envelope is proved there.
 */
class BuildResponseDataTest extends RunnerTestCase
{
    private const NODE = 'Node\\A';

    public function testSuccessWithResponseAnswersTypeAndPublicFields(): void
    {
        $definition = ProcessDefinition::for('In', 'CheckoutOrder')
            ->node(self::NODE)->response(ReserveStockResponse::class)->terminal()
            ->build();

        $outcome = $this->outcomeOf($definition, static fn(ProcessContext $c): NodeResult => NodeResult::ok([
            'reservationId' => 'R-1',
            'quantity' => 3,
        ]));

        $this->assertSame(
            ['@type' => 'checkoutOrder', 'reservationId' => 'R-1', 'quantity' => 3, 'note' => 'none'],
            (new BuildResponseData())($outcome, 'CheckoutOrder')
        );
    }

    public function testSuccessWithoutResponseAnswersTypeAndNodeData(): void
    {
        $definition = ProcessDefinition::for('In', 'CheckoutOrder')->node(self::NODE)->terminal()->build();

        $outcome = $this->outcomeOf($definition, static fn(ProcessContext $c): NodeResult => NodeResult::ok(['state' => 'done']));

        $this->assertSame(
            ['@type' => 'checkoutOrder', 'state' => 'done'],
            (new BuildResponseData())($outcome, 'CheckoutOrder')
        );
    }

    public function testSuccessWithoutAnyDataAnswersOnlyTheType(): void
    {
        $definition = ProcessDefinition::for('In', 'CheckoutOrder')->node(self::NODE)->terminal()->build();

        $outcome = $this->outcomeOf($definition, static fn(ProcessContext $c): NodeResult => NodeResult::ok([]));

        $this->assertSame(['@type' => 'checkoutOrder'], (new BuildResponseData())($outcome, 'CheckoutOrder'));
    }

    public function testTheTypeStaysFirstWhenTheResponseNamesItsOwn(): void
    {
        $outcome = new ProcessOutcome(ResponseStatus::Success, (object) ['@type' => 'other', 'a' => 1]);

        $data = (new BuildResponseData())($outcome, 'CheckoutOrder');

        $this->assertSame(['@type' => 'checkoutOrder', 'a' => 1], $data);
    }

    public function testValidationAnswersTypeAndFieldsWithPaths(): void
    {
        $fields = [
            ['field' => 'lines[1].qty', 'reason' => 'min', 'message' => 'Must be at least 1', 'messageKey' => 'q.min'],
            ['field' => 'email', 'reason' => 'missing', 'message' => 'Required'],
        ];
        $form = new ValidationFailure('process.inputInvalid', $fields, ['extra' => 'dropped']);

        $data = (new BuildResponseData())(new ProcessOutcome(ResponseStatus::ValidationError, $form), 'CheckoutOrder');

        $this->assertSame(['@type' => 'validation', 'fields' => $fields], $data);
    }

    public function testNotFoundAnswersTypeAndContext(): void
    {
        $form = new NotFound('order.notFound', ['orderNumber' => 'ORD-404']);

        $data = (new BuildResponseData())(new ProcessOutcome(ResponseStatus::NotFound, $form), 'CheckoutOrder');

        $this->assertSame(['@type' => 'notFound', 'context' => ['orderNumber' => 'ORD-404']], $data);
    }

    public function testNotFoundWithoutContextAnswersAnEmptyObject(): void
    {
        $data = (new BuildResponseData())(
            new ProcessOutcome(ResponseStatus::NotFound, new NotFound('order.notFound')),
            'CheckoutOrder'
        );

        $this->assertEquals(new stdClass(), $data['context']);
        $this->assertStringContainsString('"context":{}', (string) json_encode($data));
    }

    public function testConflictAnswersReasonAggregateContextAndViolated(): void
    {
        $form = new Conflict('order.conflict', ['expectedVersion' => 3], 'stale', 'order', ['uq_order_number']);

        $data = (new BuildResponseData())(new ProcessOutcome(ResponseStatus::Conflict, $form), 'CheckoutOrder');

        $this->assertSame(
            [
                '@type' => 'concurrencyConflict',
                'reason' => 'stale',
                'aggregate' => 'order',
                'context' => ['expectedVersion' => 3],
                'violated' => ['uq_order_number'],
            ],
            $data
        );
    }

    public function testConflictWithoutNamedReasonAndAggregateAnswersEmptyTexts(): void
    {
        $form = new Conflict('process.transientConflict', [], null, null, []);

        $data = (new BuildResponseData())(new ProcessOutcome(ResponseStatus::Conflict, $form), 'CheckoutOrder');

        $this->assertSame('', $data['reason']);
        $this->assertSame('', $data['aggregate']);
        $this->assertEquals(new stdClass(), $data['context']);
        $this->assertSame([], $data['violated']);
    }

    public function testRuleViolationAnswersRuleMessageKeyAndContext(): void
    {
        $form = new RuleViolation('OrderMustBeOpen', 'order.rule.open', ['status' => 'closed']);

        $data = (new BuildResponseData())(new ProcessOutcome(ResponseStatus::RuleViolation, $form), 'CheckoutOrder');

        $this->assertSame(
            [
                '@type' => 'ruleViolation',
                'rule' => 'OrderMustBeOpen',
                'messageKey' => 'order.rule.open',
                'context' => ['status' => 'closed'],
            ],
            $data
        );
    }

    public function testRejectOfARealRunAnswersItsFormData(): void
    {
        $definition = ProcessDefinition::for('In', 'CheckoutOrder')->node(self::NODE)->terminal()->build();

        $outcome = $this->outcomeOf($definition, static fn(ProcessContext $c): NodeResult => NodeResult::reject(
            ResponseStatus::Conflict,
            'order.clash',
            ['reason' => 'stale', 'aggregate' => 'order', 'violated' => ['uq'], 'orderNumber' => 'O-1']
        ));

        $data = (new BuildResponseData())($outcome, 'CheckoutOrder');

        $this->assertSame(409, $outcome->status()->value);
        $this->assertSame(
            [
                '@type' => 'concurrencyConflict',
                'reason' => 'stale',
                'aggregate' => 'order',
                'context' => ['orderNumber' => 'O-1'],
                'violated' => ['uq'],
                'messageKey' => 'order.clash',
            ],
            $data
        );
    }

    public function testOtherClientErrorPassesItsResponseThroughWithoutType(): void
    {
        $definition = ProcessDefinition::for('In', 'CheckoutOrder')->node(self::NODE)->terminal()->build();

        $outcome = $this->outcomeOf($definition, static fn(ProcessContext $c): NodeResult => NodeResult::reject(
            ResponseStatus::Forbidden,
            'order.forbidden',
            ['role' => 'guest']
        ));

        $this->assertSame(
            ['role' => 'guest', 'messageKey' => 'order.forbidden'],
            (new BuildResponseData())($outcome, 'CheckoutOrder')
        );
    }

    public function testServerErrorAnswersNoData(): void
    {
        $outcome = new ProcessOutcome(ResponseStatus::InternalError, new stdClass(), [], [], new \RuntimeException('boom'));

        $this->assertSame([], (new BuildResponseData())($outcome, 'CheckoutOrder'));
    }

    public function testDeclaredKeyOfTheRejectingNodeJoinsEveryClientErrorAfterTheForm(): void
    {
        $definition = ProcessDefinition::for('In', 'CheckoutOrder')->node(self::NODE)->terminal()->build();
        $cases = [
            [ResponseStatus::NotFound, ['orderNumber' => 'X'], ['@type' => 'notFound', 'context' => ['orderNumber' => 'X']]],
            [
                ResponseStatus::ValidationError,
                ['fields' => [['field' => 'a', 'reason' => 'invalid', 'message' => 'm']]],
                ['@type' => 'validation', 'fields' => [['field' => 'a', 'reason' => 'invalid', 'message' => 'm']]],
            ],
            [
                ResponseStatus::Conflict,
                ['reason' => 'stale', 'aggregate' => 'order'],
                [
                    '@type' => 'concurrencyConflict',
                    'reason' => 'stale',
                    'aggregate' => 'order',
                    'context' => new stdClass(),
                    'violated' => [],
                ],
            ],
            [
                ResponseStatus::RuleViolation,
                ['rule' => 'OrderMustBeOpen'],
                ['@type' => 'ruleViolation', 'rule' => 'OrderMustBeOpen', 'messageKey' => 'order.declared', 'context' => new stdClass()],
            ],
        ];

        foreach ($cases as [$status, $context, $form]) {
            $outcome = $this->outcomeOf($definition, static fn(ProcessContext $c): NodeResult => NodeResult::reject(
                $status,
                'order.declared',
                $context
            ));

            $expected = $status === ResponseStatus::RuleViolation ? $form : $form + ['messageKey' => 'order.declared'];
            $this->assertEquals($expected, (new BuildResponseData())($outcome, 'CheckoutOrder'), $status->name);
            if ($status !== ResponseStatus::RuleViolation) {
                $this->assertSame('messageKey', array_key_last((new BuildResponseData())($outcome, 'CheckoutOrder')), $status->name);
            }
        }
    }

    public function testDeclaredKeyOfAClientErrorWithoutFormIsKept(): void
    {
        $definition = ProcessDefinition::for('In', 'CheckoutOrder')->node(self::NODE)->terminal()->build();

        $outcome = $this->outcomeOf($definition, static fn(ProcessContext $c): NodeResult => NodeResult::reject(
            ResponseStatus::Forbidden,
            'order.forbidden'
        ));

        $this->assertSame(
            ['messageKey' => 'order.forbidden'],
            (new BuildResponseData())($outcome, 'CheckoutOrder')
        );
    }

    public function testNoKeyIsDeclaredForSuccessOrAnEmptyKey(): void
    {
        $definition = ProcessDefinition::for('In', 'CheckoutOrder')->node(self::NODE)->terminal()->build();
        $notFound = $this->outcomeOf($definition, static fn(ProcessContext $c): NodeResult => NodeResult::reject(
            ResponseStatus::NotFound,
            ''
        ));
        $success = $this->outcomeOf($definition, static fn(ProcessContext $c): NodeResult => NodeResult::ok(['a' => 1]));

        $this->assertArrayNotHasKey('messageKey', (new BuildResponseData())($notFound, 'CheckoutOrder'));
        $this->assertNull($success->declaredMessageKey());
        $this->assertSame(['@type' => 'checkoutOrder', 'a' => 1], (new BuildResponseData())($success, 'CheckoutOrder'));
    }

    public function testKeysOfThePackageLayersNeverReachTheData(): void
    {
        $outcome = (new \JardisSupport\Workflow\Handler\Failure\BuildFailureOutcome())(
            ResponseStatus::NotFound,
            'process.something',
            ['k' => 'v']
        );

        $this->assertNull($outcome->declaredMessageKey());
        $this->assertSame(
            ['@type' => 'notFound', 'context' => ['k' => 'v']],
            (new BuildResponseData())($outcome, 'CheckoutOrder')
        );
    }

    public function testRejectOfAnInputNodeAnswersFieldPathsBelowTheInputProperty(): void
    {
        $definition = ProcessDefinition::for('In', 'CheckoutOrder')->node(self::NODE)->input('customer')->terminal()->build();
        $fields = [
            ['reason' => 'missing', 'field' => 'email', 'message' => 'Required', 'messageKey' => 'k.missing'],
            ['field' => 'address.zip', 'reason' => 'invalid', 'message' => 'Bad'],
        ];

        $outcome = $this->outcomeOf($definition, static fn(ProcessContext $c): NodeResult => NodeResult::reject(
            ResponseStatus::ValidationError,
            'customer.invalid',
            ['fields' => $fields]
        ));

        // The pre-move failureData(): ['field' => $input . '.' . $finding['field']] + $finding.
        $this->assertSame(
            [
                '@type' => 'validation',
                'fields' => [
                    ['field' => 'customer.email', 'reason' => 'missing', 'message' => 'Required', 'messageKey' => 'k.missing'],
                    ['field' => 'customer.address.zip', 'reason' => 'invalid', 'message' => 'Bad'],
                ],
                'messageKey' => 'customer.invalid',
            ],
            (new BuildResponseData())($outcome, 'CheckoutOrder')
        );
    }

    public function testRejectOfANodeWithoutInputPropertyKeepsItsFieldPaths(): void
    {
        $definition = ProcessDefinition::for('In', 'CheckoutOrder')->node(self::NODE)->terminal()->build();
        $fields = [['field' => 'email', 'reason' => 'missing', 'message' => 'Required']];

        $outcome = $this->outcomeOf($definition, static fn(ProcessContext $c): NodeResult => NodeResult::reject(
            ResponseStatus::ValidationError,
            'x.invalid',
            ['fields' => $fields]
        ));

        $this->assertSame($fields, (new BuildResponseData())($outcome, 'CheckoutOrder')['fields']);
    }

    public function testInputPropertyDoesNotTouchOtherStatuses(): void
    {
        $definition = ProcessDefinition::for('In', 'CheckoutOrder')->node(self::NODE)->input('customer')->terminal()->build();

        $outcome = $this->outcomeOf($definition, static fn(ProcessContext $c): NodeResult => NodeResult::reject(
            ResponseStatus::NotFound,
            'customer.notFound',
            ['fields' => 'kept', 'id' => 1]
        ));

        $this->assertSame(['fields' => 'kept', 'id' => 1], (new BuildResponseData())($outcome, 'CheckoutOrder')['context']);
    }

    public function testTopLevelDateGoesOutAsAtomString(): void
    {
        $expires = new \DateTimeImmutable('2026-10-17T12:30:45+02:00');
        $outcome = new ProcessOutcome(ResponseStatus::Success, (object) ['accessToken' => 't', 'expiresAt' => $expires]);

        $data = (new BuildResponseData())($outcome, 'Login');

        $this->assertSame('2026-10-17T12:30:45+02:00', $data['expiresAt']);
        $this->assertSame(['@type' => 'login', 'accessToken' => 't', 'expiresAt' => '2026-10-17T12:30:45+02:00'], $data);
        $this->assertStringContainsString('"expiresAt":"2026-10-17T12:30:45+02:00"', (string) json_encode($data));
        $this->assertNotFalse(\DateTimeImmutable::createFromFormat(\DATE_ATOM, $data['expiresAt']));
    }

    public function testMutableDateTimeAndUtcAreProjectedToo(): void
    {
        $outcome = new ProcessOutcome(ResponseStatus::Success, (object) [
            'at' => new \DateTime('2026-01-02 03:04:05', new \DateTimeZone('UTC')),
        ]);

        $this->assertSame('2026-01-02T03:04:05+00:00', (new BuildResponseData())($outcome, 'P')['at']);
    }

    public function testNestedDatesInArraysListsAndObjectsAreProjected(): void
    {
        $day = new \DateTimeImmutable('2026-03-04T05:06:07+00:00');
        $inner = new stdClass();
        $inner->since = $day;
        $inner->label = 'x';
        $outcome = new ProcessOutcome(ResponseStatus::Success, (object) [
            'session' => ['issuedAt' => $day, 'history' => [$day, ['again' => $day]]],
            'owner' => $inner,
        ]);

        $data = (new BuildResponseData())($outcome, 'P');

        $this->assertSame(
            ['issuedAt' => '2026-03-04T05:06:07+00:00', 'history' => ['2026-03-04T05:06:07+00:00', ['again' => '2026-03-04T05:06:07+00:00']]],
            $data['session']
        );
        $this->assertEquals((object) ['since' => '2026-03-04T05:06:07+00:00', 'label' => 'x'], $data['owner']);
        $this->assertStringNotContainsString('timezone_type', (string) json_encode($data));
        $this->assertSame($day, $inner->since, 'the response object itself is not touched');
    }

    public function testNullDateAndNonDateValuesPassThroughUnchanged(): void
    {
        $plain = new stdClass();
        $plain->a = 1;
        $enum = ResponseStatus::Success;
        $outcome = new ProcessOutcome(ResponseStatus::Success, (object) [
            'expiresAt' => null,
            'count' => 3,
            'tags' => ['a', 'b'],
            'plain' => $plain,
            'status' => $enum,
        ]);

        $data = (new BuildResponseData())($outcome, 'P');

        $this->assertNull($data['expiresAt']);
        $this->assertSame(3, $data['count']);
        $this->assertSame(['a', 'b'], $data['tags']);
        $this->assertSame($plain, $data['plain'], 'an object without a date comes back as the same instance');
        $this->assertSame($enum, $data['status'], 'an enum keeps its own wire form');
    }

    public function testJsonSerializableKeepsItsOwnWireForm(): void
    {
        $value = new class implements \JsonSerializable {
            public \DateTimeImmutable $hidden;

            public function __construct()
            {
                $this->hidden = new \DateTimeImmutable('2026-01-01T00:00:00+00:00');
            }

            public function jsonSerialize(): string
            {
                return 'own';
            }
        };
        $outcome = new ProcessOutcome(ResponseStatus::Success, (object) ['v' => $value]);

        $data = (new BuildResponseData())($outcome, 'P');

        $this->assertSame($value, $data['v']);
        $this->assertStringContainsString('"v":"own"', (string) json_encode($data));
    }

    public function testDateInARejectContextGoesOutAsAtomString(): void
    {
        $until = new \DateTimeImmutable('2026-10-17T12:00:00+00:00');
        $definition = ProcessDefinition::for('In', 'CheckoutOrder')->node(self::NODE)->terminal()->build();

        $outcome = $this->outcomeOf($definition, static fn(ProcessContext $c): NodeResult => NodeResult::reject(
            ResponseStatus::RuleViolation,
            'order.locked',
            ['lockedUntil' => $until]
        ));

        $this->assertSame(
            '2026-10-17T12:00:00+00:00',
            (new BuildResponseData())($outcome, 'CheckoutOrder')['context']['lockedUntil']
        );
    }

    /**
     * @param callable(ProcessContext): NodeResult $node
     */
    private function outcomeOf(ProcessDefinition $definition, callable $node): ProcessOutcome
    {
        return $this->runner()->run($definition, new stdClass(), null, $this->factory([self::NODE => $node]));
    }
}
