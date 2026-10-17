<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Tests\Integration\Runner;

use JardisSupport\Workflow\Data\NodeResult;
use JardisSupport\Workflow\Data\ProcessContext;
use JardisSupport\Workflow\Data\ProcessDefinition;
use JardisSupport\Workflow\Data\Route;
use JardisSupport\Workflow\Tests\Support\RunnerTestCase;

/**
 * What a node sees of the run: the data of earlier nodes only through previous() / latest() (A-K1),
 * and the most recent run of a node that two paths lead to (A-K6).
 */
class NodeContextTest extends RunnerTestCase
{
    private const A = 'Node\\A';
    private const B = 'Node\\B';
    private const C = 'Node\\C';
    private const D = 'Node\\D';

    public function testFollowingNodeSeesEarlierDataOnlyThroughPreviousAndLatest(): void
    {
        $definition = ProcessDefinition::for('In', 'P')
            ->node(self::A)->onTrue(self::B)
            ->node(self::B)->terminal()
            ->build();
        $contexts = [];
        $record = static function (ProcessContext $c) use (&$contexts): NodeResult {
            $contexts[] = $c;

            return NodeResult::ok(['from' => count($contexts)]);
        };

        $this->runner()->run($definition, new \stdClass(), null, $this->factory([self::A => $record, self::B => $record]));

        [$first, $second] = $contexts;
        $this->assertNull($first->previous());
        $this->assertSame(['from' => 1], $second->previous()?->data());
        $this->assertSame(['from' => 1], $second->latest(self::A)?->data());
        $this->assertNull($second->latest(self::B), 'a node has no result before it ran');
        $this->assertNotSame($first, $second, 'every step gets a new context');
        $this->assertCount(0, $first->chain(), 'the earlier context is not changed afterwards');
        $this->assertCount(1, $second->chain());
    }

    public function testTwoPathsIntoOneNodeLatestIsTheMostRecentRun(): void
    {
        $definition = ProcessDefinition::for('In', 'P')
            ->node(self::A)->onTrue(self::C)
            ->node(self::C)->onFalse(self::B)->onTrue(self::D)
            ->node(self::B)->onTrue(self::C)
            ->node(self::D)->terminal()
            ->build();
        $atEnd = null;
        $nodes = [
            self::A => static fn(ProcessContext $c): NodeResult => NodeResult::ok([]),
            self::B => static fn(ProcessContext $c): NodeResult => NodeResult::ok([]),
            self::C => static fn(ProcessContext $c): NodeResult => $c->iteration(self::C) === 0
                ? NodeResult::route(Route::OnFalse, ['run' => 'first'])
                : NodeResult::route(Route::OnTrue, ['run' => 'second']),
            self::D => static function (ProcessContext $c) use (&$atEnd): NodeResult {
                $atEnd = $c;

                return NodeResult::ok([]);
            },
        ];

        $this->runner()->run($definition, new \stdClass(), null, $this->factory($nodes));

        $this->assertInstanceOf(ProcessContext::class, $atEnd);
        $this->assertSame(['run' => 'second'], $atEnd->latest(self::C)?->data());
        $this->assertSame(
            [['run' => 'first'], ['run' => 'second']],
            array_map(static fn(NodeResult $r): mixed => $r->data(), $atEnd->all(self::C))
        );
        $this->assertSame(2, $atEnd->iteration(self::C));
    }
}
