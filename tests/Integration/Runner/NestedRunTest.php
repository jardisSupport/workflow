<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Tests\Integration\Runner;

use JardisSupport\Contract\Kernel\ResponseStatus;
use JardisSupport\Workflow\Data\NodeResult;
use JardisSupport\Workflow\Data\ProcessContext;
use JardisSupport\Workflow\Data\ProcessDefinition;
use JardisSupport\Workflow\Data\Route;
use JardisSupport\Workflow\Tests\Support\RunnerTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * A run inside a run, on the bare engine: depth, no ports, a rejected sub-process, the events and the
 * 400 of the inner run, the loop limit per run. The transaction half of these cases (savepoints by depth,
 * rollback) lives with the bracket in `jardissupport/repository`.
 */
class NestedRunTest extends RunnerTestCase
{
    private const A = 'Node\\A';
    private const B = 'Node\\B';
    private const C = 'Node\\C';
    private const INNER_A = 'Inner\\A';
    private const INNER_B = 'Inner\\B';
    private const DEEP = 'Deep\\A';

    private function inner(): ProcessDefinition
    {
        return ProcessDefinition::for('In', 'Inner')
            ->node(self::INNER_A)->onTrue(self::INNER_B)
            ->node(self::INNER_B)->terminal()
            ->build();
    }

    public function testASubProcessRunsOneLevelDeeperThanTheRunThatCallsIt(): void
    {
        $runner = $this->runner();
        $depths = [];
        $record = static function (string $where) use (&$depths): callable {
            return static function (ProcessContext $c) use (&$depths, $where): NodeResult {
                $depths[$where] = $c->depth();

                return NodeResult::ok([]);
            };
        };
        $deepest = ProcessDefinition::for('In', 'Deepest')->node(self::DEEP)->terminal()->build();
        $inner = ProcessDefinition::for('In', 'Inner')->node(self::INNER_A)->terminal()->build();
        $outer = ProcessDefinition::for('In', 'Outer')->node(self::A)->onTrue(self::B)->node(self::B)->terminal()->build();
        $deepNodes = $this->factory([self::DEEP => $record('deepest')]);
        $innerNodes = $this->factory([
            self::INNER_A => function (ProcessContext $c) use ($runner, $deepest, $deepNodes, &$depths): NodeResult {
                $depths['inner'] = $c->depth();

                return $this->subProcess($runner, $deepest, $deepNodes)($c);
            },
        ]);

        $runner->run($outer, new \stdClass(), null, $this->factory([
            self::A => $record('outer'),
            self::B => $this->subProcess($runner, $inner, $innerNodes),
        ]));

        $this->assertSame(['outer' => 0, 'inner' => 1, 'deepest' => 2], $depths);
    }

    /**
     * @return array<string, array{bool}>
     */
    public static function transactionalFlags(): array
    {
        return ['transactional (default)' => [true], 'opted out' => [false]];
    }

    #[DataProvider('transactionalFlags')]
    public function testARunNeedsNoConnectionAndNoPortWhateverTheTransactionFlagSays(bool $transactional): void
    {
        $built = [];
        $definition = ProcessDefinition::for('In', 'P')
            ->transactional($transactional)
            ->node(self::A)->onTrue(self::B)
            ->node(self::B)->terminal()
            ->build();

        $outcome = $this->runner()->run($definition, new \stdClass(), null, $this->factory([
            self::A => static fn(ProcessContext $c): NodeResult => NodeResult::ok(['a' => 1]),
            self::B => static fn(ProcessContext $c): NodeResult => NodeResult::ok(['k' => 'v']),
        ], $built));

        $this->assertSame(ResponseStatus::Success, $outcome->status());
        $this->assertSame([self::A, self::B], $built, 'the flag is for the door; the engine runs the nodes');
        $this->assertEquals((object) ['k' => 'v'], $outcome->response());
    }

    public function testRejectedSubProcessRoutesOnFalseAndTheOuterRunGoesOn(): void
    {
        $runner = $this->runner();
        $definition = ProcessDefinition::for('In', 'Outer')
            ->node(self::A)->onTrue(self::B)
            ->node(self::B)->onTrue(self::C)->onFalse(self::C)
            ->node(self::C)->terminal()
            ->build();
        $ran = [];
        $note = static function (string $what, ?ResponseStatus $rejects = null) use (&$ran): callable {
            return static function (ProcessContext $c) use (&$ran, $what, $rejects): NodeResult {
                $ran[] = $what;

                return $rejects === null ? NodeResult::ok([]) : NodeResult::reject($rejects, 'inner.rule');
            };
        };
        $innerNodes = $this->factory([
            self::INNER_A => $note('inner-a'),
            self::INNER_B => $note('inner-b', ResponseStatus::RuleViolation),
        ]);

        $outcome = $runner->run($definition, new \stdClass(), null, $this->factory([
            self::A => $note('outer-a'),
            self::B => $this->subProcess($runner, $this->inner(), $innerNodes, Route::OnFalse),
            self::C => $note('outer-c'),
        ]));

        $this->assertSame(ResponseStatus::Success, $outcome->status());
        $this->assertSame(['outer-a', 'inner-a', 'inner-b', 'outer-c'], $ran, 'the dev onFalse carries the outer run on');
    }

    public function testOuterOutcomeCarriesTheEventsOfTheInnerRun(): void
    {
        $runner = $this->runner();
        $innerEvent = new \stdClass();
        $outerEvent = new \stdClass();
        $definition = ProcessDefinition::for('In', 'Outer')
            ->node(self::A)->onTrue(self::B)
            ->node(self::B)->terminal()
            ->build();
        $innerNodes = $this->factory([
            self::INNER_A => static fn(ProcessContext $c): NodeResult => NodeResult::ok(['n' => 1])->withEvents($innerEvent),
            self::INNER_B => static fn(ProcessContext $c): NodeResult => NodeResult::ok(['n' => 2]),
        ]);

        $outcome = $runner->run($definition, new \stdClass(), null, $this->factory([
            self::A => $this->subProcess($runner, $this->inner(), $innerNodes),
            self::B => static fn(ProcessContext $c): NodeResult => NodeResult::ok(['done' => true])->withEvents($outerEvent),
        ]));

        $this->assertSame([$innerEvent, $outerEvent], $outcome->events());
    }

    public function testInner400IsRejectedWith400AtTheOuterCallWithItsFieldPaths(): void
    {
        $runner = $this->runner();
        $inner = ProcessDefinition::for('In', 'Inner')->node(self::INNER_A)->input('order')->terminal()->build();
        $definition = ProcessDefinition::for('In', 'Outer')->node(self::A)->terminal()->build();
        $finding = ['field' => 'customer', 'reason' => 'missing', 'message' => 'Required', 'messageKey' => 'validation.missing'];

        $outcome = $runner->run($definition, new \stdClass(), null, $this->factory([
            self::A => $this->subProcess($runner, $inner, $this->factory([
                self::INNER_A => static fn(ProcessContext $c): NodeResult => NodeResult::reject(
                    ResponseStatus::ValidationError,
                    '',
                    ['fields' => [$finding]],
                    ['customer is required']
                ),
            ])),
        ]));

        $this->assertSame(ResponseStatus::ValidationError, $outcome->status());
        $this->assertSame('order.customer', $outcome->context()['fields'][0]['field'], 'the inner prefix travels up');
        $this->assertSame('validation.missing', $outcome->context()['fields'][0]['messageKey'], 'the key of the finding travels');
        $this->assertSame(['customer is required'], $outcome->errors());
    }

    public function testLoopLimitCountsEachRunOnItsOwn(): void
    {
        $runner = $this->runner();
        $definition = ProcessDefinition::for('In', 'Outer')
            ->loopLimit(3)
            ->node(self::A)->onTrue(self::B)
            ->node(self::B)->onTrue(self::C)
            ->node(self::C)->terminal()
            ->build();
        $inner = ProcessDefinition::for('In', 'Inner')
            ->loopLimit(2)
            ->node(self::INNER_A)->onTrue(self::INNER_B)
            ->node(self::INNER_B)->terminal()
            ->build();
        $ok = static fn(ProcessContext $c): NodeResult => NodeResult::ok(['n' => 1]);
        $innerNodes = $this->factory([self::INNER_A => $ok, self::INNER_B => $ok]);

        $outcome = $runner->run($definition, new \stdClass(), null, $this->factory([
            self::A => $ok,
            self::B => $this->subProcess($runner, $inner, $innerNodes),
            self::C => $ok,
        ]));

        $this->assertSame(ResponseStatus::Success, $outcome->status(), '3 + 2 steps, yet no run exceeds its own limit');
    }
}
