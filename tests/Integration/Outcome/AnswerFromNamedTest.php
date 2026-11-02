<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Tests\Integration\Outcome;

use JardisSupport\Workflow\Data\NodeResult;
use JardisSupport\Workflow\Data\ProcessContext;
use JardisSupport\Workflow\Data\ProcessDefinition;
use JardisSupport\Workflow\Data\ProcessOutcome;
use JardisSupport\Workflow\Data\Route;
use JardisSupport\Workflow\Exception\ConstructionException;
use JardisSupport\Workflow\Handler\Outcome\MarkOutcome;
use JardisSupport\Workflow\Tests\Support\RunnerTestCase;

/**
 * The named form of answerFrom(): the candidate that ran last gives its outcome name, the answer carries it as the
 * first property `@outcome`; without a name the answer is the 5.1 answer.
 */
class AnswerFromNamedTest extends RunnerTestCase
{
    private const START = 'Flow\\Start';
    private const ONE = 'Flow\\One';
    private const TWO = 'Flow\\Two';
    private const THREE = 'Flow\\Three';

    /**
     * START routes to ONE (OnTrue), TWO (OnFalse) or THREE (OnSkip); each of the three ends the run.
     *
     * @param list<mixed> $entries
     */
    private function fork(array $entries): ProcessDefinition
    {
        return ProcessDefinition::for('In', 'P')
            ->answerFrom(...$entries)
            ->node(self::START)->onTrue(self::ONE)->onFalse(self::TWO)->onSkip(self::THREE)
            ->node(self::ONE)->terminal()
            ->node(self::TWO)->terminal()
            ->node(self::THREE)->terminal()
            ->build();
    }

    /**
     * @param array<string, callable(ProcessContext): NodeResult> $overrides
     */
    private function runFlow(ProcessDefinition $definition, Route $start, array $overrides = []): ProcessOutcome
    {
        $nodes = $overrides + [
            self::START => static fn(ProcessContext $c): NodeResult => NodeResult::route($start),
            self::ONE => static fn(ProcessContext $c): NodeResult => NodeResult::ok(['a' => 1]),
            self::TWO => static fn(ProcessContext $c): NodeResult => NodeResult::ok(['b' => 2, 'c' => 3]),
            self::THREE => static fn(ProcessContext $c): NodeResult => NodeResult::ok(['d' => 4]),
        ];

        return $this->runner()->run($definition, new \stdClass(), $this->factory($nodes));
    }

    /**
     * @return array<string, string>
     */
    private function three(): array
    {
        return [self::ONE => 'first', self::TWO => 'second', self::THREE => 'third'];
    }

    public function testThreeOutcomesEachCarryTheirOwnNameAndFieldsInOrder(): void
    {
        $definition = $this->fork([$this->three()]);

        $first = $this->runFlow($definition, Route::OnTrue)->response();
        $second = $this->runFlow($definition, Route::OnFalse)->response();
        $third = $this->runFlow($definition, Route::OnSkip)->response();

        $this->assertSame('{"@outcome":"first","a":1}', json_encode($first));
        $this->assertSame('{"@outcome":"second","b":2,"c":3}', json_encode($second));
        $this->assertSame('{"@outcome":"third","d":4}', json_encode($third));
    }

    public function testOneOutcome(): void
    {
        $definition = ProcessDefinition::for('In', 'P')
            ->answerFrom([self::ONE => 'only'])
            ->node(self::ONE)->terminal()
            ->build();

        $outcome = $this->runner()->run($definition, new \stdClass(), $this->factory([
            self::ONE => static fn(ProcessContext $c): NodeResult => NodeResult::ok(['a' => 1]),
        ]));

        $this->assertSame('{"@outcome":"only","a":1}', json_encode($outcome->response()));
    }

    public function testANodeWithoutDataAnswersOnlyTheMarker(): void
    {
        $outcome = $this->runFlow($this->fork([$this->three()]), Route::OnTrue, [
            self::ONE => static fn(ProcessContext $c): NodeResult => NodeResult::route(Route::OnTrue),
        ]);

        $this->assertSame('{"@outcome":"first"}', json_encode($outcome->response()));
    }

    public function testWithoutANameTheAnswerIsTheOldOneWithoutMarker(): void
    {
        $outcome = $this->runFlow($this->fork([[self::ONE, self::TWO, self::THREE]]), Route::OnFalse);

        $this->assertSame('{"b":2,"c":3}', json_encode($outcome->response()));
    }

    public function testTwoCandidatesMayShareOneName(): void
    {
        $definition = $this->fork([[self::ONE => 'done', self::TWO => 'done', self::THREE => 'other']]);

        $one = $this->runFlow($definition, Route::OnTrue)->response();
        $two = $this->runFlow($definition, Route::OnFalse)->response();

        $this->assertSame('{"@outcome":"done","a":1}', json_encode($one));
        $this->assertSame('{"@outcome":"done","b":2,"c":3}', json_encode($two));
    }

    public function testMixingNamedAndListEntriesIsRefusedAtBuild(): void
    {
        $this->expectException(ConstructionException::class);

        $this->fork([[self::ONE => 'first'], [self::TWO]]);
    }

    public function testAStepFieldOfTheMarkerNameLosesAgainstTheMarker(): void
    {
        $outcome = $this->runFlow($this->fork([$this->three()]), Route::OnFalse, [
            self::TWO => static fn(ProcessContext $c): NodeResult => NodeResult::ok(
                ['b' => 2, MarkOutcome::KEY => 'forged', 'c' => 3]
            ),
        ]);

        $this->assertSame('{"@outcome":"second","b":2,"c":3}', json_encode($outcome->response()));
    }

    public function testACandidateWithANameThatDoesNotRunIsNoError(): void
    {
        // THREE is a named candidate, but the run takes the branch to ONE: the unused name is simply not used
        $outcome = $this->runFlow($this->fork([$this->three()]), Route::OnTrue);

        $this->assertSame('{"@outcome":"first","a":1}', json_encode($outcome->response()));
    }
}
