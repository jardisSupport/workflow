<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Tests\Integration\Failure;

use JardisSupport\Contract\Kernel\ResponseStatus;
use JardisSupport\Workflow\Data\Failure\Conflict;
use JardisSupport\Workflow\Data\Failure\NotFound;
use JardisSupport\Workflow\Data\Failure\RuleViolation;
use JardisSupport\Workflow\Data\Failure\ValidationFailure;
use JardisSupport\Workflow\Data\NodeResult;
use JardisSupport\Workflow\Data\ProcessContext;
use JardisSupport\Workflow\Data\ProcessDefinition;
use JardisSupport\Workflow\Handler\Failure\BuildFailureForm;
use JardisSupport\Workflow\Handler\Failure\ConcurrencyConflictPayload;
use JardisSupport\Workflow\Handler\Failure\NotFoundPayload;
use JardisSupport\Workflow\Handler\Failure\RuleViolationPayload;
use JardisSupport\Workflow\Handler\Failure\ValidationPayload;
use JardisSupport\Workflow\Handler\Node\MapResponse;
use JardisSupport\Workflow\Tests\Support\FakeDomainResponse;
use JardisSupport\Workflow\Tests\Support\TransactionTestCase;

/**
 * BuildFailureForm and the four payload closures build the 400/404/409/422 forms (W8, W9);
 * BuildOutcome answers with them.
 */
class BuildFailureFormTest extends TransactionTestCase
{
    private const A = 'Node\\A';

    public function testValidationPayloadBuildsTheValidationFailure(): void
    {
        $fields = [
            ['field' => 'email', 'reason' => 'missing', 'message' => 'Required', 'messageKey' => 'order.email.missing'],
            ['field' => 'items[1].qty', 'reason' => 'invalid', 'message' => 'Too small'],
        ];

        $form = (new ValidationPayload())('order.invalid', $fields, ['form' => 'x']);

        $this->assertInstanceOf(ValidationFailure::class, $form);
        $this->assertSame(ResponseStatus::ValidationError, $form->status());
        $this->assertSame('order.invalid', $form->messageKey());
        $this->assertSame($fields, $form->fields());
        $this->assertSame(['form' => 'x'], $form->context());
    }

    public function testNotFoundPayloadBuildsTheNotFound(): void
    {
        $form = (new NotFoundPayload())('order.notFound', ['orderNumber' => 'ORD-404']);

        $this->assertInstanceOf(NotFound::class, $form);
        $this->assertSame('order.notFound', $form->messageKey());
        $this->assertSame(['orderNumber' => 'ORD-404'], $form->context());
    }

    public function testConcurrencyConflictPayloadBuildsTheConflict(): void
    {
        $form = (new ConcurrencyConflictPayload())('order.stale', 'stale', 'order', ['orderNumber' => 'O-1'], ['uq_order']);

        $this->assertInstanceOf(Conflict::class, $form);
        $this->assertSame('order.stale', $form->messageKey());
        $this->assertSame('stale', $form->reason());
        $this->assertSame('order', $form->aggregate());
        $this->assertSame(['orderNumber' => 'O-1'], $form->context());
        $this->assertSame(['uq_order'], $form->violated());
    }

    public function testRuleViolationPayloadBuildsTheRuleViolation(): void
    {
        $form = (new RuleViolationPayload())('OrderMustBeOpen', 'order.rule.open', ['id' => 1]);

        $this->assertInstanceOf(RuleViolation::class, $form);
        $this->assertSame('OrderMustBeOpen', $form->rule());
        $this->assertSame('order.rule.open', $form->messageKey());
        $this->assertSame(['id' => 1], $form->context());
    }

    public function testBuildFailureFormMapsTheFourStatusesToTheirForms(): void
    {
        $build = new BuildFailureForm();
        $fields = [['field' => 'a', 'reason' => 'missing', 'message' => 'm', 'messageKey' => 'k']];

        $validation = $build(ResponseStatus::ValidationError, 'v.key', ['fields' => $fields, 'extra' => 1], []);
        $notFound = $build(ResponseStatus::NotFound, 'n.key', ['orderNumber' => 'X'], []);
        $conflict = $build(
            ResponseStatus::Conflict,
            'c.key',
            ['reason' => 'stale', 'aggregate' => 'order', 'violated' => ['uq'], 'expectedVersion' => 3],
            []
        );
        $rule = $build(ResponseStatus::RuleViolation, 'r.key', ['rule' => 'Open', 'id' => 7], []);

        $this->assertEquals(new ValidationFailure('v.key', $fields, ['extra' => 1]), $validation);
        $this->assertEquals(new NotFound('n.key', ['orderNumber' => 'X']), $notFound);
        $this->assertEquals(new Conflict('c.key', ['expectedVersion' => 3], 'stale', 'order', ['uq']), $conflict);
        $this->assertEquals(new RuleViolation('Open', 'r.key', ['id' => 7]), $rule);
    }

    public function testStatusesWithoutAFormGiveNull(): void
    {
        $build = new BuildFailureForm();

        foreach ([ResponseStatus::Unauthorized, ResponseStatus::Forbidden, ResponseStatus::MethodNotAllowed] as $status) {
            $this->assertNull($build($status, 'k', [], []), $status->name);
        }
    }

    public function testValidationWithoutFieldsReportsEachErrorAsAFinding(): void
    {
        $form = (new BuildFailureForm())(ResponseStatus::ValidationError, 'v', [], ['first', 'second']);

        $this->assertInstanceOf(ValidationFailure::class, $form);
        $this->assertSame(
            [
                ['field' => '', 'reason' => 'invalid', 'message' => 'first'],
                ['field' => '', 'reason' => 'invalid', 'message' => 'second'],
            ],
            $form->fields()
        );
    }

    public function testFieldErrorsOfACalleeKeepTheirStructureAllTheWayToTheOutcome(): void
    {
        $fields = [
            ['field' => 'items[1].qty', 'reason' => 'invalid', 'message' => 'Too small', 'messageKey' => 'order.qty.min'],
            ['field' => 'email', 'reason' => 'missing', 'message' => 'Required'],
        ];
        $definition = ProcessDefinition::for('In', 'P')->node(self::A)->terminal()->build();

        $outcome = $this->runnerOn()->run($definition, new \stdClass(), null, $this->factory([
            self::A => static fn(ProcessContext $c): NodeResult => (new MapResponse())(new FakeDomainResponse(
                400,
                ['messageKey' => 'order.invalid', 'fields' => $fields],
                ['validator' => ['Too small']]
            )),
        ]));

        $form = $outcome->response();
        $this->assertSame(ResponseStatus::ValidationError, $outcome->status());
        $this->assertInstanceOf(ValidationFailure::class, $form);
        $this->assertSame('order.invalid', $form->messageKey());
        $this->assertSame($fields, $form->fields());
        $this->assertSame([], $form->context());
        $this->assertSame(['Too small'], $outcome->errors());
    }

    public function testBuildOutcomeAnswersWithTheFormOfEachRejectStatus(): void
    {
        $definition = ProcessDefinition::for('In', 'P')->node(self::A)->terminal()->build();
        $cases = [
            [ResponseStatus::NotFound, NotFound::class],
            [ResponseStatus::Conflict, Conflict::class],
            [ResponseStatus::RuleViolation, RuleViolation::class],
            [ResponseStatus::ValidationError, ValidationFailure::class],
        ];

        foreach ($cases as [$status, $class]) {
            $outcome = $this->runnerOn()->run($definition, new \stdClass(), null, $this->factory([
                self::A => static fn(ProcessContext $c): NodeResult => NodeResult::reject($status, 'p.key'),
            ]));

            $this->assertSame($status, $outcome->status());
            $this->assertInstanceOf($class, $outcome->response(), $status->name);
        }
    }

    public function testRejectWithoutAFormKeepsKeyAndContextInTheAnswer(): void
    {
        $definition = ProcessDefinition::for('In', 'P')->node(self::A)->terminal()->build();

        $outcome = $this->runnerOn()->run($definition, new \stdClass(), null, $this->factory([
            self::A => static fn(ProcessContext $c): NodeResult => NodeResult::reject(
                \JardisSupport\Contract\Kernel\ResponseStatus::Forbidden,
                'p.forbidden',
                ['role' => 'guest']
            ),
        ]));

        $this->assertSame(ResponseStatus::Forbidden, $outcome->status());
        $this->assertEquals((object) ['messageKey' => 'p.forbidden', 'context' => ['role' => 'guest']], $outcome->response());
    }
}
