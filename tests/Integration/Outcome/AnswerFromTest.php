<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Tests\Integration\Outcome;

use JardisSupport\Contract\Kernel\ResponseStatus;
use JardisSupport\Workflow\Data\NodeResult;
use JardisSupport\Workflow\Data\ProcessContext;
use JardisSupport\Workflow\Data\ProcessDefinition;
use JardisSupport\Workflow\Data\ProcessOutcome;
use JardisSupport\Workflow\Data\Route;
use JardisSupport\Workflow\Exception\ConstructionException;
use JardisSupport\Workflow\Tests\Support\Into\BuiltReference;
use JardisSupport\Workflow\Tests\Support\RunnerTestCase;

/**
 * The answer from the end of the run: per declared entry the LAST executed candidate step gives its data, the
 * entries are united, the package knows no field name; a reject answers as before.
 */
class AnswerFromTest extends RunnerTestCase
{
    private const FIRST = 'Flow\\First';
    private const SECOND = 'Flow\\Second';
    private const THIRD = 'Flow\\Third';

    /**
     * @param array<string, callable(ProcessContext): NodeResult> $nodes
     */
    private function runFlow(ProcessDefinition $definition, array $nodes): ProcessOutcome
    {
        return $this->runner()->run($definition, new \stdClass(), $this->factory($nodes));
    }

    /**
     * FIRST -> SECOND -> THIRD, all three declared; the entries decide what the answer is.
     *
     * @param list<list<string>> $entries
     */
    private function chain(array $entries): ProcessDefinition
    {
        return ProcessDefinition::for('In', 'P')
            ->answerFrom(...$entries)
            ->node(self::FIRST)->onTrue(self::SECOND)
            ->node(self::SECOND)->onTrue(self::THIRD)
            ->node(self::THIRD)->terminal()
            ->build();
    }

    public function testTheAnswerIsNotTheLastNodeButTheNamedOne(): void
    {
        $outcome = $this->runFlow($this->chain([[self::SECOND]]), [
            self::FIRST => static fn(ProcessContext $c): NodeResult => NodeResult::ok(['one' => 1]),
            self::SECOND => static fn(ProcessContext $c): NodeResult => NodeResult::ok(['reference' => 'R-2']),
            self::THIRD => static fn(ProcessContext $c): NodeResult => NodeResult::ok(['ignored' => true]),
        ]);

        $this->assertSame(ResponseStatus::Success, $outcome->status());
        $this->assertEquals((object) ['reference' => 'R-2'], $outcome->response());
    }

    public function testTwoCandidatesOfOneEntryTheLastExecutedWins(): void
    {
        // declared order SECOND, FIRST - the executed order decides: FIRST ran first, SECOND later
        $outcome = $this->runFlow($this->chain([[self::SECOND, self::FIRST]]), [
            self::FIRST => static fn(ProcessContext $c): NodeResult => NodeResult::ok(['reference' => 'from-first']),
            self::SECOND => static fn(ProcessContext $c): NodeResult => NodeResult::ok(['reference' => 'from-second']),
            self::THIRD => static fn(ProcessContext $c): NodeResult => NodeResult::route(Route::OnTrue),
        ]);

        $this->assertEquals((object) ['reference' => 'from-second'], $outcome->response());
    }

    public function testOnlyOneCandidateRanTheOtherIsNotNeeded(): void
    {
        $definition = ProcessDefinition::for('In', 'P')
            ->answerFrom([self::SECOND, self::THIRD])
            ->node(self::FIRST)->onTrue(self::SECOND)->onFalse(self::THIRD)
            ->node(self::SECOND)->terminal()
            ->node(self::THIRD)->terminal()
            ->build();

        $outcome = $this->runFlow($definition, [
            self::FIRST => static fn(ProcessContext $c): NodeResult => NodeResult::route(Route::OnFalse),
            self::SECOND => static fn(ProcessContext $c): NodeResult => NodeResult::ok(['reference' => 'second']),
            self::THIRD => static fn(ProcessContext $c): NodeResult => NodeResult::ok(['reference' => 'third']),
        ]);

        $this->assertEquals((object) ['reference' => 'third'], $outcome->response());
    }

    public function testALoopPassTheLastPassOfTheCandidateGives(): void
    {
        $definition = ProcessDefinition::for('In', 'P')
            ->answerFrom([self::FIRST])
            ->node(self::FIRST)->onTrue(self::SECOND)
            ->node(self::SECOND)->onTrue(self::FIRST)->onFalse(null)
            ->build();

        $outcome = $this->runFlow($definition, [
            self::FIRST => static fn(ProcessContext $c): NodeResult => NodeResult::ok(
                ['pass' => $c->iteration(self::FIRST)]
            ),
            self::SECOND => static fn(ProcessContext $c): NodeResult => $c->iteration(self::SECOND) < 2
                ? NodeResult::ok([])
                : NodeResult::route(Route::OnFalse),
        ]);

        $this->assertEquals((object) ['pass' => 2], $outcome->response());
    }

    public function testEntriesAreUnitedFromArrayCarrierAndObjectCarrier(): void
    {
        $outcome = $this->runFlow($this->chain([[self::FIRST], [self::SECOND], [self::THIRD]]), [
            // scalar carrier: the node hands a value as a one-field array
            self::FIRST => static fn(ProcessContext $c): NodeResult => NodeResult::ok(['active' => true]),
            // object carrier: its public properties
            self::SECOND => static fn(ProcessContext $c): NodeResult => NodeResult::ok(new BuiltReference('R-9', 3)),
            self::THIRD => static fn(ProcessContext $c): NodeResult => NodeResult::ok(['rows' => [['a' => 1]]]),
        ]);

        $this->assertEquals(
            (object) ['active' => true, 'reference' => 'R-9', 'count' => 3, 'rows' => [['a' => 1]]],
            $outcome->response()
        );
    }

    public function testAnEntryWithoutDataAddsNoField(): void
    {
        $outcome = $this->runFlow($this->chain([[self::FIRST], [self::SECOND]]), [
            self::FIRST => static fn(ProcessContext $c): NodeResult => NodeResult::route(Route::OnTrue),
            self::SECOND => static fn(ProcessContext $c): NodeResult => NodeResult::ok([]),
            self::THIRD => static fn(ProcessContext $c): NodeResult => NodeResult::ok(['ignored' => true]),
        ]);

        $this->assertEquals(new \stdClass(), $outcome->response());
    }

    public function testNoEntryAtAllAnswersAnEmptyObject(): void
    {
        $outcome = $this->runFlow($this->chain([]), [
            self::FIRST => static fn(ProcessContext $c): NodeResult => NodeResult::ok(['ignored' => true]),
            self::SECOND => static fn(ProcessContext $c): NodeResult => NodeResult::ok(['ignored' => true]),
            self::THIRD => static fn(ProcessContext $c): NodeResult => NodeResult::ok(['ignored' => true]),
        ]);

        $this->assertSame(ResponseStatus::Success, $outcome->status());
        $this->assertEquals(new \stdClass(), $outcome->response());
    }

    public function testAnEntryNoStepGaveIsAConstructionException(): void
    {
        $definition = ProcessDefinition::for('In', 'P')
            ->answerFrom([self::SECOND])
            ->node(self::FIRST)->onTrue(self::SECOND)->onFalse(null)
            ->node(self::SECOND)->terminal()
            ->build();

        $this->expectException(ConstructionException::class);
        $this->expectExceptionMessage('Answer entry 1 (Flow\\Second) was not given');

        $this->runFlow($definition, [
            self::FIRST => static fn(ProcessContext $c): NodeResult => NodeResult::route(Route::OnFalse),
            self::SECOND => static fn(ProcessContext $c): NodeResult => NodeResult::ok([]),
        ]);
    }

    public function testAListAsStepDataIsAConstructionException(): void
    {
        $this->expectException(ConstructionException::class);
        $this->expectExceptionMessage('needs fields by name');

        $this->runFlow($this->chain([[self::SECOND]]), [
            self::FIRST => static fn(ProcessContext $c): NodeResult => NodeResult::ok([]),
            self::SECOND => static fn(ProcessContext $c): NodeResult => NodeResult::ok([['a' => 1], ['a' => 2]]),
            self::THIRD => static fn(ProcessContext $c): NodeResult => NodeResult::ok([]),
        ]);
    }

    public function testAScalarAsStepDataIsAConstructionException(): void
    {
        $this->expectException(ConstructionException::class);

        $this->runFlow($this->chain([[self::SECOND]]), [
            self::FIRST => static fn(ProcessContext $c): NodeResult => NodeResult::ok([]),
            self::SECOND => static fn(ProcessContext $c): NodeResult => NodeResult::route(Route::OnTrue, 'text'),
            self::THIRD => static fn(ProcessContext $c): NodeResult => NodeResult::ok([]),
        ]);
    }

    public function testTheSameFieldFromTwoEntriesIsAConstructionException(): void
    {
        $this->expectException(ConstructionException::class);
        $this->expectExceptionMessage('names the field "reference" twice');

        $this->runFlow($this->chain([[self::FIRST], [self::SECOND]]), [
            self::FIRST => static fn(ProcessContext $c): NodeResult => NodeResult::ok(['reference' => 'a']),
            self::SECOND => static fn(ProcessContext $c): NodeResult => NodeResult::ok(['reference' => 'b']),
            self::THIRD => static fn(ProcessContext $c): NodeResult => NodeResult::ok([]),
        ]);
    }

    public function testARejectAnswersAsBeforeWithoutTheEntries(): void
    {
        $outcome = $this->runFlow($this->chain([[self::FIRST]]), [
            self::FIRST => static fn(ProcessContext $c): NodeResult => NodeResult::ok(['reference' => 'a']),
            self::SECOND => static fn(ProcessContext $c): NodeResult => NodeResult::reject(
                ResponseStatus::RuleViolation,
                'p.refused',
                ['rule' => 'R']
            ),
            self::THIRD => static fn(ProcessContext $c): NodeResult => NodeResult::ok([]),
        ]);

        $this->assertSame(ResponseStatus::RuleViolation, $outcome->status());
        $this->assertSame('p.refused', $outcome->messageKey());
        $this->assertSame(['rule' => 'R'], $outcome->context());
        $this->assertEquals(new \stdClass(), $outcome->response());
    }

    public function testTheEventsOfAllStepsTravelOnlyTheEntriesGiveTheAnswer(): void
    {
        $event = new \stdClass();
        $outcome = $this->runFlow($this->chain([[self::FIRST]]), [
            self::FIRST => static fn(ProcessContext $c): NodeResult => NodeResult::ok(['reference' => 'a']),
            self::SECOND => static fn(ProcessContext $c): NodeResult => NodeResult::ok([])->withEvents($event),
            self::THIRD => static fn(ProcessContext $c): NodeResult => NodeResult::ok([]),
        ]);

        $this->assertSame([$event], $outcome->events());
        $this->assertEquals((object) ['reference' => 'a'], $outcome->response());
    }

    public function testWithoutTheDeclarationTheAnswerIsStillTheLastNode(): void
    {
        $definition = ProcessDefinition::for('In', 'P')
            ->node(self::FIRST)->onTrue(self::SECOND)
            ->node(self::SECOND)->terminal()
            ->build();

        $outcome = $this->runFlow($definition, [
            self::FIRST => static fn(ProcessContext $c): NodeResult => NodeResult::ok(['one' => 1]),
            self::SECOND => static fn(ProcessContext $c): NodeResult => NodeResult::ok(['two' => 2]),
        ]);

        $this->assertEquals((object) ['two' => 2], $outcome->response());
    }
}
