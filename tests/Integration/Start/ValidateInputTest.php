<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Tests\Integration\Start;

use JardisSupport\Contract\Kernel\ResponseStatus;
use JardisSupport\Workflow\Data\Failure\ValidationFailure;
use JardisSupport\Workflow\Data\NodeResult;
use JardisSupport\Workflow\Data\ProcessContext;
use JardisSupport\Workflow\Data\ProcessDefinition;
use JardisSupport\Workflow\Tests\Support\Process\Order\OrderInput;
use JardisSupport\Workflow\Tests\Support\Process\Order\OrderLine;
use JardisSupport\Workflow\Tests\Support\Process\Order\OrderValidation;
use JardisSupport\Workflow\Tests\Support\TransactionTestCase;

/**
 * The start validates the input with the injected validator (real ObjectValidator) before any
 * node, context or transaction exists (A-S2, A-S4, A-V5).
 */
class ValidateInputTest extends TransactionTestCase
{
    private const NODE = 'Node\\Place';

    private function definition(): ProcessDefinition
    {
        return ProcessDefinition::for(OrderInput::class, 'PlaceOrder')
            ->validate(OrderValidation::class)
            ->node(self::NODE)->terminal()
            ->build();
    }

    public function testInvalidInputAnswers400WithFieldPathsAndTouchesNothing(): void
    {
        $built = [];
        $input = new OrderInput(null, [new OrderLine('A-1', 2), new OrderLine('B-7', 0)]);

        $outcome = $this->runnerOn()->run(
            $this->definition(),
            $input,
            null,
            $this->factory([self::NODE => $this->writes('node')], $built)
        );

        $this->assertSame(ResponseStatus::ValidationError, $outcome->status());
        $form = $outcome->response();
        $this->assertInstanceOf(ValidationFailure::class, $form);
        $this->assertSame('process.inputInvalid', $form->messageKey());
        $fields = array_column($form->fields(), null, 'field');
        $this->assertSame(['customer', 'items[1].qty'], array_keys($fields));
        $this->assertSame('missing', $fields['customer']['reason']);
        $this->assertSame('invalid', $fields['items[1].qty']['reason']);
        $this->assertNotSame('', $fields['items[1].qty']['message']);
        $this->assertContains($fields['items[1].qty']['message'], $outcome->errors());
        $this->assertSame([], $built, 'no node runs');
        $this->assertSame([], $this->connection->calls(), 'no transaction, no savepoint');
        $this->assertSame([], $this->ledger->notes());
        $this->assertSame([], $outcome->events());
    }

    public function testValidInputRunsTheProcess(): void
    {
        $built = [];

        $outcome = $this->runnerOn()->run(
            $this->definition(),
            new OrderInput('Ada', []),
            null,
            $this->factory([self::NODE => $this->writes('node', ['ok' => true])], $built)
        );

        $this->assertSame(ResponseStatus::Success, $outcome->status());
        $this->assertSame([self::NODE], $built);
        $this->assertSame(['node'], $this->ledger->notes());
    }

    public function testWithoutADeclaredValidatorNothingIsChecked(): void
    {
        $definition = ProcessDefinition::for(OrderInput::class, 'PlaceOrder')
            ->node(self::NODE)->terminal()
            ->build();

        $outcome = $this->runnerOn()->run(
            $definition,
            new OrderInput(null, [new OrderLine('A-1', 0)]),
            null,
            $this->factory([self::NODE => $this->writes('node')])
        );

        $this->assertSame(ResponseStatus::Success, $outcome->status());
    }

    public function testTheDeclaredValidatorClassIsNeverInstantiated(): void
    {
        $definition = ProcessDefinition::for(OrderInput::class, 'PlaceOrder')
            ->validate('Missing\\NoSuchValidator')
            ->node(self::NODE)->terminal()
            ->build();

        $outcome = $this->runnerOn()->run(
            $definition,
            new OrderInput('Ada', []),
            null,
            $this->factory([self::NODE => static fn(ProcessContext $c): NodeResult => NodeResult::ok([])])
        );

        $this->assertSame(ResponseStatus::Success, $outcome->status(), 'the injected port validates, not the class');
    }
}
