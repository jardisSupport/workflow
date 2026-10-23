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

        $outcome = $this->runner()->run($definition, new \stdClass(), $this->factory([
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

        $outcome = $this->runner()->run($definition, new \stdClass(), $this->factory([
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
        $this->assertSame('p.clash', $outcome->messageKey());
        $this->assertSame(['field' => 'x'], $outcome->context());
        $this->assertEquals(new \stdClass(), $outcome->response(), 'the engine builds no failure form');
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

        $outcome = $this->runner()->run($definition, new \stdClass(), $this->factory([
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

        $outcome = $this->runner()->run($definition, new \stdClass(), $this->factory([
            self::A => static fn(ProcessContext $c): NodeResult => NodeResult::route(Route::OnFalse),
            self::B => static fn(ProcessContext $c): NodeResult => NodeResult::ok(['via' => 'B']),
            self::C => static fn(ProcessContext $c): NodeResult => NodeResult::ok(['via' => 'C']),
        ], $built));

        $this->assertSame(\JardisSupport\Workflow\Data\RuleMode::Decision, $definition->getRuleMode(self::A));
        $this->assertSame([self::A, self::C], $built);
        $this->assertEquals((object) ['via' => 'C'], $outcome->response());
    }

    public function testEventEndNodeWithoutDataAnswersAnEmptyObject(): void
    {
        // workflow 3.0: kein Durchreichen (STOPP 2, 9b) - the answer is what the last node returned.
        $definition = ProcessDefinition::for('In', 'P')
            ->node(self::A)->onTrue(self::B)
            ->node(self::B)->onTrue(self::C)
            ->node(self::C)->onTrue(self::D)
            ->node(self::D)->terminal()
            ->build();
        $e1 = new \stdClass();
        $e2 = new \stdClass();

        $outcome = $this->runner()->run($definition, new \stdClass(), $this->factory([
            self::A => static fn(ProcessContext $c): NodeResult => NodeResult::ok(['id' => 7]),
            self::B => static fn(ProcessContext $c): NodeResult => NodeResult::route(Route::OnTrue)->withEvents($e1),
            self::C => static fn(ProcessContext $c): NodeResult => NodeResult::route(Route::OnTrue),
            self::D => static fn(ProcessContext $c): NodeResult => NodeResult::route(Route::OnTrue)->withEvents($e2),
        ]));

        $this->assertEquals(new \stdClass(), $outcome->response());
        $this->assertSame('{}', json_encode($outcome->response()));
        $this->assertSame([$e1, $e2], $outcome->events());
    }

    public function testMiddleNodeWithoutDataLeavesTheFollowerToReadThePredecessorExplicitly(): void
    {
        // workflow 3.0: kein Durchreichen (STOPP 2, 9b) - previous() is the dataless node, latest() the answer node.
        $definition = ProcessDefinition::for('In', 'P')
            ->node(self::A)->onTrue(self::B)
            ->node(self::B)->onTrue(self::C)
            ->node(self::C)->terminal()
            ->build();
        $seenPrevious = 'unset';

        $outcome = $this->runner()->run($definition, new \stdClass(), $this->factory([
            self::A => static fn(ProcessContext $c): NodeResult => NodeResult::ok(['id' => 7]),
            self::B => static fn(ProcessContext $c): NodeResult => NodeResult::route(Route::OnTrue)->withEvents(new \stdClass()),
            self::C => static function (ProcessContext $c) use (&$seenPrevious): NodeResult {
                $seenPrevious = $c->previous()?->data();

                return NodeResult::ok(['id' => $c->latest(self::A)?->data()['id'] ?? null]);
            },
        ]));

        $this->assertNull($seenPrevious, 'nothing is handed on from node A through node B');
        $this->assertEquals((object) ['id' => 7], $outcome->response());
    }

    public function testRejectIsUntouchedByTheMissingPassThrough(): void
    {
        // workflow 3.0: kein Durchreichen (STOPP 2, 9b) - a reject keeps its key and context.
        $definition = ProcessDefinition::for('In', 'P')
            ->node(self::A)->onTrue(self::B)
            ->node(self::B)->terminal()
            ->build();

        $outcome = $this->runner()->run($definition, new \stdClass(), $this->factory([
            self::A => static fn(ProcessContext $c): NodeResult => NodeResult::route(Route::OnTrue),
            self::B => static fn(ProcessContext $c): NodeResult => NodeResult::reject(ResponseStatus::Conflict, 'p.clash', ['field' => 'x']),
        ]));

        $this->assertSame(ResponseStatus::Conflict, $outcome->status());
        $this->assertSame('p.clash', $outcome->messageKey());
        $this->assertSame(['field' => 'x'], $outcome->context());
        $this->assertEquals(new \stdClass(), $outcome->response(), 'the engine builds no failure form');
    }

    public function testWithoutAnyAnswerAnEventNodeSeesNullAndTheRunAnswersAnEmptyObject(): void
    {
        // workflow 3.0: kein Durchreichen (STOPP 2, 9b) - formerly the follower saw [].
        $definition = ProcessDefinition::for('In', 'P')
            ->node(self::A)->onTrue(self::B)
            ->node(self::B)->terminal()
            ->build();
        $seen = 'unset';

        $outcome = $this->runner()->run($definition, new \stdClass(), $this->factory([
            self::A => static fn(ProcessContext $c): NodeResult => NodeResult::route(Route::OnTrue)->withEvents(new \stdClass()),
            self::B => static function (ProcessContext $c) use (&$seen): NodeResult {
                $seen = $c->previous()?->data();

                return NodeResult::route(Route::OnTrue);
            },
        ]));

        $this->assertNull($seen);
        $this->assertEquals(new \stdClass(), $outcome->response());
    }
}
