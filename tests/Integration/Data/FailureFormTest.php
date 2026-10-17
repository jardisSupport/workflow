<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Tests\Integration\Data;

use JardisSupport\Contract\Kernel\ResponseStatus;
use JardisSupport\Workflow\Data\Failure\Conflict;
use JardisSupport\Workflow\Data\Failure\FailureForm;
use JardisSupport\Workflow\Data\Failure\NotFound;
use JardisSupport\Workflow\Data\Failure\RuleViolation;
use JardisSupport\Workflow\Data\Failure\ValidationFailure;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * The four failure DTOs: fixed status, key, context and their one extra datum.
 */
class FailureFormTest extends TestCase
{
    public function testValidationFailureIs400WithFields(): void
    {
        $fields = [['field' => 'email', 'reason' => 'missing', 'message' => 'Required']];

        $failure = new ValidationFailure('input.invalid', $fields, ['form' => 'signup']);

        $this->assertInstanceOf(FailureForm::class, $failure);
        $this->assertSame(ResponseStatus::ValidationError, $failure->status());
        $this->assertSame('input.invalid', $failure->messageKey());
        $this->assertSame(['form' => 'signup'], $failure->context());
        $this->assertSame($fields, $failure->fields());
        $this->assertFinalReadonly($failure);
    }

    public function testNotFoundIs404(): void
    {
        $failure = new NotFound('order.notFound', ['orderNo' => 'A-1']);

        $this->assertInstanceOf(FailureForm::class, $failure);
        $this->assertSame(ResponseStatus::NotFound, $failure->status());
        $this->assertSame('order.notFound', $failure->messageKey());
        $this->assertSame(['orderNo' => 'A-1'], $failure->context());
        $this->assertFinalReadonly($failure);
    }

    public function testConflictIs409(): void
    {
        $failure = new Conflict('order.alreadyShipped');

        $this->assertInstanceOf(FailureForm::class, $failure);
        $this->assertSame(ResponseStatus::Conflict, $failure->status());
        $this->assertSame('order.alreadyShipped', $failure->messageKey());
        $this->assertSame([], $failure->context());
        $this->assertFinalReadonly($failure);
    }

    public function testConflictCarriesReasonAggregateAndViolatedConstraints(): void
    {
        $failure = new Conflict('order.stale', ['orderNo' => 'A-1'], 'stale', 'order', ['uq_order_number']);

        $this->assertSame('stale', $failure->reason());
        $this->assertSame('order', $failure->aggregate());
        $this->assertSame(['uq_order_number'], $failure->violated());
        $this->assertNull((new Conflict('k'))->reason());
        $this->assertNull((new Conflict('k'))->aggregate());
        $this->assertSame([], (new Conflict('k'))->violated());
    }

    public function testValidationFieldMayCarryItsOwnMessageKey(): void
    {
        $fields = [['field' => 'qty', 'reason' => 'invalid', 'message' => 'Too small', 'messageKey' => 'order.qty.min']];

        $this->assertSame($fields, (new ValidationFailure('input.invalid', $fields))->fields());
    }

    public function testRuleViolationIs422WithRuleName(): void
    {
        $failure = new RuleViolation('StockAvailable', 'stock.insufficient', ['sku' => 'S-9']);

        $this->assertInstanceOf(FailureForm::class, $failure);
        $this->assertSame(ResponseStatus::RuleViolation, $failure->status());
        $this->assertSame('StockAvailable', $failure->rule());
        $this->assertSame('stock.insufficient', $failure->messageKey());
        $this->assertSame(['sku' => 'S-9'], $failure->context());
        $this->assertFinalReadonly($failure);
    }

    private function assertFinalReadonly(object $subject): void
    {
        $reflection = new ReflectionClass($subject);

        $this->assertTrue($reflection->isFinal());
        $this->assertTrue($reflection->isReadOnly());
    }
}
