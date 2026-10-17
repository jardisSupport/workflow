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

    /**
     * @param callable(ProcessContext): NodeResult $node
     */
    private function outcomeOf(ProcessDefinition $definition, callable $node): ProcessOutcome
    {
        return $this->runner()->run($definition, new stdClass(), null, $this->factory([self::NODE => $node]));
    }
}
