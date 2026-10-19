<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Tests\Integration\Runner;

use JardisSupport\Contract\Kernel\ResponseStatus;
use JardisSupport\Workflow\Data\Answer;
use JardisSupport\Workflow\Data\NodeResult;
use JardisSupport\Workflow\Data\ProcessContext;
use JardisSupport\Workflow\Data\ProcessDefinition;
use JardisSupport\Workflow\Data\Route;
use JardisSupport\Workflow\Exception\ConstructionException;
use JardisSupport\Workflow\Tests\Support\RunnerTestCase;

/**
 * The form of the answer of a successful run (O1, D8): the data of the last step by default, or the data of all
 * steps united in run order. The engine runs without a door.
 */
class AnswerTest extends RunnerTestCase
{
    private const A = 'Node\\A';
    private const B = 'Node\\B';
    private const C = 'Node\\C';

    public function testDefaultIsLastNode(): void
    {
        $definition = ProcessDefinition::for('In', 'P')->node(self::A)->terminal()->build();

        $this->assertSame(Answer::LastNode, $definition->getAnswer());
        $this->assertSame('lastNode', Answer::LastNode->value);
        $this->assertSame('merged', Answer::Merged->value);
    }

    public function testLastNodeAnswersTheDataOfTheLastStep(): void
    {
        $definition = ProcessDefinition::for('In', 'P')
            ->answer(Answer::LastNode)
            ->node(self::A)->onTrue(self::B)
            ->node(self::B)->terminal()
            ->build();

        $outcome = $this->runner()->run($definition, new \stdClass(), null, $this->factory([
            self::A => static fn(ProcessContext $c): NodeResult => NodeResult::ok(['a' => 1]),
            self::B => static fn(ProcessContext $c): NodeResult => NodeResult::ok(['b' => 2]),
        ]));

        $this->assertSame(ResponseStatus::Success, $outcome->status());
        $this->assertEquals((object) ['b' => 2], $outcome->response());
    }

    public function testMergedUnitesAllStepsAndLaterKeysWin(): void
    {
        $definition = ProcessDefinition::for('In', 'P')
            ->answer(Answer::Merged)
            ->node(self::A)->onTrue(self::B)
            ->node(self::B)->onTrue(self::C)
            ->node(self::C)->terminal()
            ->build();

        $outcome = $this->runner()->run($definition, new \stdClass(), null, $this->factory([
            self::A => static fn(ProcessContext $c): NodeResult => NodeResult::ok(['id' => 7, 'state' => 'draft']),
            self::B => static fn(ProcessContext $c): NodeResult => NodeResult::route(Route::OnTrue),
            self::C => static fn(ProcessContext $c): NodeResult => NodeResult::ok(['state' => 'done', 'total' => 30]),
        ]));

        $this->assertSame(ResponseStatus::Success, $outcome->status());
        $this->assertSame(
            ['id' => 7, 'state' => 'done', 'total' => 30],
            (array) $outcome->response(),
            'the step without data adds no field, the later state wins, the order is the run order'
        );
    }

    public function testMergedUnitesLoopRepetitionsInRunOrder(): void
    {
        $definition = ProcessDefinition::for('In', 'P')
            ->answer(Answer::Merged)
            ->node(self::A)->onTrue(self::B)
            ->node(self::B)->onTrue(self::A)->onExit(null)
            ->build();

        $outcome = $this->runner()->run($definition, new \stdClass(), null, $this->factory([
            self::A => static fn(ProcessContext $c): NodeResult => NodeResult::ok([
                'round' . $c->iteration(self::A) => 'a',
                'last' => $c->iteration(self::A),
            ]),
            self::B => static fn(ProcessContext $c): NodeResult => $c->iteration(self::B) < 2
                ? NodeResult::route(Route::OnTrue)
                : NodeResult::route(Route::OnExit),
        ]));

        $this->assertSame(
            ['round0' => 'a', 'last' => 2, 'round1' => 'a', 'round2' => 'a'],
            (array) $outcome->response(),
            'three repetitions of A in run order, the last repetition wins on the shared field'
        );
    }

    public function testMergedTakesTheFieldsOfATypedResponseObject(): void
    {
        $typed = new class () {
            public int $id = 7;
            public string $number = 'A-7';
            private string $hidden = 'not part of the answer';

            public function hidden(): string
            {
                return $this->hidden;
            }
        };
        $definition = ProcessDefinition::for('In', 'P')
            ->answer(Answer::Merged)
            ->node(self::A)->onTrue(self::B)
            ->node(self::B)->terminal()
            ->build();

        $outcome = $this->runner()->run($definition, new \stdClass(), null, $this->factory([
            self::A => static fn(ProcessContext $c): NodeResult => NodeResult::ok($typed),
            self::B => static fn(ProcessContext $c): NodeResult => NodeResult::ok(['sent' => true]),
        ]));

        $this->assertSame('not part of the answer', $typed->hidden());
        $this->assertSame(['id' => 7, 'number' => 'A-7', 'sent' => true], (array) $outcome->response());
    }

    public function testMergedRejectsAList(): void
    {
        $definition = ProcessDefinition::for('In', 'P')
            ->answer(Answer::Merged)
            ->node(self::A)->onTrue(self::B)
            ->node(self::B)->terminal()
            ->build();

        $this->expectException(ConstructionException::class);
        $this->expectExceptionMessage(self::A);

        $this->runner()->run($definition, new \stdClass(), null, $this->factory([
            self::A => static fn(ProcessContext $c): NodeResult => NodeResult::ok(['first', 'second']),
            self::B => static fn(ProcessContext $c): NodeResult => NodeResult::ok(['b' => 1]),
        ]));
    }

    public function testLastNodeRejectsDataThatIsNeitherArrayNorObject(): void
    {
        $definition = ProcessDefinition::for('In', 'P')->node(self::A)->terminal()->build();

        $this->expectException(ConstructionException::class);

        $this->runner()->run($definition, new \stdClass(), null, $this->factory([
            self::A => static fn(ProcessContext $c): NodeResult => NodeResult::route(Route::OnTrue, 'text'),
        ]));
    }

    public function testMergedRejectsDataThatIsNeitherArrayNorObject(): void
    {
        $definition = ProcessDefinition::for('In', 'P')
            ->answer(Answer::Merged)
            ->node(self::A)->onTrue(self::B)
            ->node(self::B)->terminal()
            ->build();

        $this->expectException(ConstructionException::class);

        $this->runner()->run($definition, new \stdClass(), null, $this->factory([
            self::A => static fn(ProcessContext $c): NodeResult => NodeResult::route(Route::OnTrue, 42),
            self::B => static fn(ProcessContext $c): NodeResult => NodeResult::ok(['b' => 1]),
        ]));
    }

    public function testMergedTreatsNullAndAnEmptyListAsNoFields(): void
    {
        $definition = ProcessDefinition::for('In', 'P')
            ->answer(Answer::Merged)
            ->node(self::A)->onTrue(self::B)
            ->node(self::B)->onTrue(self::C)
            ->node(self::C)->terminal()
            ->build();

        $outcome = $this->runner()->run($definition, new \stdClass(), null, $this->factory([
            self::A => static fn(ProcessContext $c): NodeResult => NodeResult::route(Route::OnTrue, null),
            self::B => static fn(ProcessContext $c): NodeResult => NodeResult::route(Route::OnTrue, []),
            self::C => static fn(ProcessContext $c): NodeResult => NodeResult::ok(['c' => 1]),
        ]));

        $this->assertSame(['c' => 1], (array) $outcome->response());
    }

    public function testAnswerCannotBeChangedOnABuiltDefinition(): void
    {
        $definition = ProcessDefinition::for('In', 'P')->node(self::A)->terminal()->build();

        $this->expectException(ConstructionException::class);

        $definition->answer(Answer::Merged);
    }
}
