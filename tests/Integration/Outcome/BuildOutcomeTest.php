<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Tests\Integration\Outcome;

use JardisSupport\Contract\Kernel\ResponseStatus;
use JardisSupport\Workflow\Data\NodeResult;
use JardisSupport\Workflow\Data\ProcessContext;
use JardisSupport\Workflow\Data\ProcessDefinition;
use JardisSupport\Workflow\Data\ProcessOutcome;
use JardisSupport\Workflow\Tests\Support\RunnerTestCase;

/**
 * The reject of a run, as the engine hands it to the door: status, message key, context, errors - nothing else.
 */
class BuildOutcomeTest extends RunnerTestCase
{
    private const NODE = 'Node\\Reject';

    private function rejectWith(ProcessDefinition $definition, NodeResult $reject): ProcessOutcome
    {
        return $this->runner()->run($definition, new \stdClass(), $this->factory([
            self::NODE => static fn(ProcessContext $c): NodeResult => $reject,
        ]));
    }

    public function testRejectCarriesOnlyStatusMessageKeyContextAndErrors(): void
    {
        $definition = ProcessDefinition::for('In', 'P')->node(self::NODE)->terminal()->build();

        $outcome = $this->rejectWith($definition, NodeResult::reject(
            ResponseStatus::Conflict,
            'p.clash',
            ['reason' => 'stale'],
            ['clash']
        )->withEvents(new \stdClass()));

        $this->assertSame(ResponseStatus::Conflict, $outcome->status());
        $this->assertSame('p.clash', $outcome->messageKey());
        $this->assertSame(['reason' => 'stale'], $outcome->context());
        $this->assertSame(['clash'], $outcome->errors());
        $this->assertEquals(new \stdClass(), $outcome->response(), 'no failure form, no data');
        $this->assertSame([], $outcome->events(), 'a rejected run reports no events');
    }

    public function testDeclaredKeyBeatsTheRejectKey(): void
    {
        $definition = ProcessDefinition::for('In', 'P')
            ->node(self::NODE)->rejects(ResponseStatus::Conflict, 'declared.clash')->terminal()
            ->build();

        $outcome = $this->rejectWith($definition, NodeResult::reject(ResponseStatus::Conflict, 'p.clash'));

        $this->assertSame('declared.clash', $outcome->messageKey());
    }

    public function testRejectKeyStandsWhenTheDeclarationIsForAnotherStatus(): void
    {
        $definition = ProcessDefinition::for('In', 'P')
            ->node(self::NODE)->rejects(ResponseStatus::NotFound, 'declared.gone')->terminal()
            ->build();

        $outcome = $this->rejectWith($definition, NodeResult::reject(ResponseStatus::Conflict, 'p.clash'));

        $this->assertSame('p.clash', $outcome->messageKey());
    }

    public function testValidationFieldsArePrefixedBelowTheInputProperty(): void
    {
        $definition = ProcessDefinition::for('In', 'P')->node(self::NODE)->input('order')->terminal()->build();

        $outcome = $this->rejectWith($definition, NodeResult::reject(
            ResponseStatus::ValidationError,
            '',
            ['fields' => [['field' => 'customer', 'reason' => 'missing'], ['field' => 'items[0].qty', 'reason' => 'min']]]
        ));

        $this->assertSame(
            [
                ['field' => 'order.customer', 'reason' => 'missing'],
                ['field' => 'order.items[0].qty', 'reason' => 'min'],
            ],
            $outcome->context()['fields']
        );
    }

    public function testOtherRejectsAreNotPrefixed(): void
    {
        $definition = ProcessDefinition::for('In', 'P')->node(self::NODE)->input('order')->terminal()->build();

        $outcome = $this->rejectWith($definition, NodeResult::reject(
            ResponseStatus::Conflict,
            'p.clash',
            ['fields' => [['field' => 'customer']]]
        ));

        $this->assertSame([['field' => 'customer']], $outcome->context()['fields']);
    }
}
