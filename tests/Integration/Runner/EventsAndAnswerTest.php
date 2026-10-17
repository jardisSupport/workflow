<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Tests\Integration\Runner;

use JardisSupport\Contract\Kernel\ResponseStatus;
use JardisSupport\Workflow\Data\Failure\Conflict;
use JardisSupport\Workflow\Data\NodeResult;
use JardisSupport\Workflow\Data\ProcessContext;
use JardisSupport\Workflow\Data\ProcessDefinition;
use JardisSupport\Workflow\Data\Route;
use JardisSupport\Workflow\Handler\Node\MapResponse;
use JardisSupport\Workflow\Tests\Support\FakeDomainResponse;
use JardisSupport\Workflow\Tests\Support\RunnerTestCase;

/**
 * Events of a run (A-N2), the rule outcomes (A-N5, A-N6) and the answer of a run that ends on
 * nodes without data (A-F3).
 */
class EventsAndAnswerTest extends RunnerTestCase
{
    private const A = 'Node\\A';
    private const B = 'Node\\B';
    private const C = 'Node\\C';
    private const D = 'Node\\D';

    public function testSuccessfulRunReportsAllEventsInExecutionOrder(): void
    {
        $e1 = new \stdClass();
        $e2 = new \stdClass();
        $e3 = new \stdClass();
        $definition = ProcessDefinition::for('In', 'P')
            ->node(self::A)->onTrue(self::B)
            ->node(self::B)->terminal()
            ->build();

        $outcome = $this->runner()->run($definition, new \stdClass(), null, $this->factory([
            self::A => static fn(ProcessContext $c): NodeResult => NodeResult::ok(['a' => 1])->withEvents($e1, $e2),
            self::B => static fn(ProcessContext $c): NodeResult => NodeResult::ok(['b' => 1])->withEvents($e3),
        ]));

        $this->assertSame(ResponseStatus::Success, $outcome->status());
        $this->assertSame([$e1, $e2, $e3], $outcome->events());
    }

    public function testRunThatEndsRejectedReportsNoEvents(): void
    {
        $definition = ProcessDefinition::for('In', 'P')
            ->node(self::A)->onTrue(self::B)
            ->node(self::B)->terminal()
            ->build();

        $outcome = $this->runner()->run($definition, new \stdClass(), null, $this->factory([
            self::A => static fn(ProcessContext $c): NodeResult => NodeResult::ok([])->withEvents(new \stdClass()),
            self::B => static fn(ProcessContext $c): NodeResult => NodeResult::reject(
                ResponseStatus::Conflict,
                'p.clash',
                ['field' => 'x'],
                ['clash']
            )->withEvents(new \stdClass()),
        ]));

        $this->assertSame(ResponseStatus::Conflict, $outcome->status());
        $this->assertSame([], $outcome->events());
        $this->assertSame(['clash'], $outcome->errors());
        $this->assertEquals(new Conflict('p.clash', ['field' => 'x'], '', '', []), $outcome->response());
    }

    public function testRuleWithoutDeclarationRejectsWith422AndNothingFollows(): void
    {
        $definition = ProcessDefinition::for('In', 'P')
            ->node(self::A)->onTrue(self::B)
            ->node(self::B)->terminal()
            ->build();
        $built = [];
        $rule = static fn(ProcessContext $c): NodeResult => (new MapResponse())(
            new FakeDomainResponse(422, ['messageKey' => 'p.rule_failed'])
        );

        $outcome = $this->runner()->run($definition, new \stdClass(), null, $this->factory([
            self::A => $rule,
            self::B => static fn(ProcessContext $c): NodeResult => NodeResult::ok([]),
        ], $built));

        $this->assertNull($definition->getRuleMode(self::A));
        $this->assertSame(ResponseStatus::RuleViolation, $outcome->status());
        $this->assertSame([self::A], $built, 'the follow-up node never runs');
    }

    public function testRuleDeclaredAsDecisionRoutesOnFalseInsteadOfRejecting(): void
    {
        $definition = ProcessDefinition::for('In', 'P')
            ->ruleMode(self::A, \JardisSupport\Workflow\Data\RuleMode::Decision)
            ->node(self::A)->onTrue(self::B)->onFalse(self::C)
            ->node(self::B)->terminal()
            ->node(self::C)->terminal()
            ->build();
        $built = [];

        $outcome = $this->runner()->run($definition, new \stdClass(), null, $this->factory([
            self::A => static fn(ProcessContext $c): NodeResult => NodeResult::route(Route::OnFalse),
            self::B => static fn(ProcessContext $c): NodeResult => NodeResult::ok(['via' => 'B']),
            self::C => static fn(ProcessContext $c): NodeResult => NodeResult::ok(['via' => 'C']),
        ], $built));

        $this->assertSame(\JardisSupport\Workflow\Data\RuleMode::Decision, $definition->getRuleMode(self::A));
        $this->assertSame([self::A, self::C], $built);
        $this->assertEquals((object) ['via' => 'C'], $outcome->response());
    }

    public function testEventRuleEventAtTheEndAnswersWithTheLastAnswerNode(): void
    {
        $definition = ProcessDefinition::for('In', 'P')
            ->node(self::A)->onTrue(self::B)
            ->node(self::B)->onTrue(self::C)
            ->node(self::C)->onTrue(self::D)
            ->node(self::D)->terminal()
            ->build();
        $e1 = new \stdClass();
        $e2 = new \stdClass();

        $outcome = $this->runner()->run($definition, new \stdClass(), null, $this->factory([
            self::A => static fn(ProcessContext $c): NodeResult => NodeResult::ok(['id' => 7]),
            self::B => static fn(ProcessContext $c): NodeResult => NodeResult::route(Route::OnTrue)->withEvents($e1),
            self::C => static fn(ProcessContext $c): NodeResult => NodeResult::route(Route::OnTrue),
            self::D => static fn(ProcessContext $c): NodeResult => NodeResult::route(Route::OnTrue)->withEvents($e2),
        ]));

        $this->assertEquals((object) ['id' => 7], $outcome->response());
        $this->assertSame([$e1, $e2], $outcome->events());
    }

    public function testWithoutAnyPrecedingAnswerAnEventNodeHandsOnAnEmptyArray(): void
    {
        $definition = ProcessDefinition::for('In', 'P')
            ->node(self::A)->onTrue(self::B)
            ->node(self::B)->terminal()
            ->build();
        $seen = 'unset';

        $outcome = $this->runner()->run($definition, new \stdClass(), null, $this->factory([
            self::A => static fn(ProcessContext $c): NodeResult => NodeResult::route(Route::OnTrue)->withEvents(new \stdClass()),
            self::B => static function (ProcessContext $c) use (&$seen): NodeResult {
                $seen = $c->previous()?->data();

                return NodeResult::route(Route::OnTrue);
            },
        ]));

        $this->assertSame([], $seen);
        $this->assertEquals(new \stdClass(), $outcome->response());
    }
}
