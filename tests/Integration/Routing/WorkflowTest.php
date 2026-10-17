<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Tests\Integration\Routing;

use JardisSupport\Contract\Kernel\ResponseStatus;
use JardisSupport\Workflow\Data\NodeResult;
use JardisSupport\Workflow\Data\ProcessContext;
use JardisSupport\Workflow\Data\ProcessDefinition;
use JardisSupport\Workflow\Data\Route;
use JardisSupport\Workflow\Exception\UnroutedStatusException;
use JardisSupport\Workflow\Handler\Routing\Workflow;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * The routing engine against real definitions: one step call per node, strict routing,
 * reject ends the run, loops, immutable contexts. The step closure is the seam to node code.
 */
class WorkflowTest extends TestCase
{
    private const A = 'Node\\A';
    private const B = 'Node\\B';
    private const C = 'Node\\C';

    /**
     * @param array<string, list<NodeResult>> $script results per node, consumed in order
     * @param list<string> $calls
     */
    private function step(array $script, array &$calls): \Closure
    {
        return static function (string $node, ProcessContext $context) use (&$script, &$calls): NodeResult {
            $calls[] = $node;
            $result = array_shift($script[$node]);
            if ($result === null) {
                throw new \LogicException('script exhausted for ' . $node);
            }

            return $result;
        };
    }

    public function testEngineIsFinalAndStrictnessHasNoSwitch(): void
    {
        $reflection = new ReflectionClass(Workflow::class);

        $this->assertTrue($reflection->isFinal());
        $this->assertSame(3, $reflection->getMethod('__invoke')->getNumberOfParameters());
    }

    public function testSingleNodeRunsOnceAndRecordsItsResult(): void
    {
        $definition = ProcessDefinition::for('In', 'P')->node(self::A)->terminal()->build();
        $calls = [];
        $result = NodeResult::ok(['v' => 1]);

        $context = (new Workflow())(
            $definition->config(),
            $this->step([self::A => [$result]], $calls),
            new ProcessContext(new \stdClass())
        );

        $this->assertSame([self::A], $calls);
        $this->assertSame($result, $context->latest(self::A));
        $this->assertCount(1, $context->chain());
    }

    public function testLinearChainFollowsOnTrueInOrderAndStepSeesPreviousResult(): void
    {
        $definition = ProcessDefinition::for('In', 'P')
            ->node(self::A)->onTrue(self::B)
            ->node(self::B)->terminal()
            ->build();
        $seen = [];
        $step = static function (string $node, ProcessContext $context) use (&$seen): NodeResult {
            $previous = $context->previous()?->data();
            $seen[$node] = is_array($previous) ? $previous['out'] : null;

            return NodeResult::ok(['out' => $node]);
        };

        $context = (new Workflow())($definition->config(), $step, new ProcessContext(new \stdClass()));

        $this->assertSame([self::A => null, self::B => self::A], $seen);
        $this->assertSame([self::A, self::B], array_column($context->chain(), 'node'));
    }

    public function testBranchTakesTheRouteTheResultNames(): void
    {
        $definition = ProcessDefinition::for('In', 'P')
            ->node(self::A)->onTrue(self::B)->onFalse(self::C)
            ->node(self::B)->terminal()
            ->node(self::C)->terminal()
            ->build();
        $calls = [];

        (new Workflow())(
            $definition->config(),
            $this->step([
                self::A => [NodeResult::route(Route::OnFalse)],
                self::C => [NodeResult::ok([])],
            ], $calls),
            new ProcessContext(new \stdClass())
        );

        $this->assertSame([self::A, self::C], $calls);
    }

    public function testEveryRouteKeyIsFollowed(): void
    {
        foreach (Route::cases() as $route) {
            $definition = ProcessDefinition::for('In', 'P')->node(self::A);
            $method = lcfirst($route->name);
            $definition->$method(self::B)->node(self::B)->terminal()->build();
            $calls = [];

            (new Workflow())(
                $definition->config(),
                $this->step([
                    self::A => [NodeResult::route($route)],
                    self::B => [NodeResult::ok([])],
                ], $calls),
                new ProcessContext(new \stdClass())
            );

            $this->assertSame([self::A, self::B], $calls, $route->name);
        }
    }

    public function testRouteWithoutTransitionKeyThrowsUnroutedStatus(): void
    {
        $definition = ProcessDefinition::for('In', 'P')
            ->node(self::A)->onTrue(self::B)
            ->node(self::B)->terminal()
            ->build();
        $calls = [];

        try {
            (new Workflow())(
                $definition->config(),
                $this->step([self::A => [NodeResult::route(Route::OnFalse)]], $calls),
                new ProcessContext(new \stdClass())
            );
            $this->fail('UnroutedStatusException expected');
        } catch (UnroutedStatusException $e) {
            $this->assertSame(self::A, $e->getNode());
            $this->assertSame('onFalse', $e->getStatus());
        }
    }

    public function testNodeWithoutAnyTransitionThrowsEvenForOk(): void
    {
        $definition = ProcessDefinition::for('In', 'P')->node(self::A)->build();
        $calls = [];

        $this->expectException(UnroutedStatusException::class);

        (new Workflow())(
            $definition->config(),
            $this->step([self::A => [NodeResult::ok([])]], $calls),
            new ProcessContext(new \stdClass())
        );
    }

    public function testNullTargetIsADeclaredEndWithoutException(): void
    {
        $definition = ProcessDefinition::for('In', 'P')
            ->node(self::A)->onTrue(self::B)->onExit(null)
            ->node(self::B)->terminal()
            ->build();
        $calls = [];

        $context = (new Workflow())(
            $definition->config(),
            $this->step([self::A => [NodeResult::route(Route::OnExit)]], $calls),
            new ProcessContext(new \stdClass())
        );

        $this->assertSame([self::A], $calls);
        $this->assertCount(1, $context->chain());
    }

    public function testRejectEndsTheRunEvenWhenRoutesAreDeclared(): void
    {
        $definition = ProcessDefinition::for('In', 'P')
            ->node(self::A)->onTrue(self::B)->onFalse(self::B)
            ->node(self::B)->terminal()
            ->build();
        $calls = [];
        $reject = NodeResult::reject(ResponseStatus::NotFound, 'a.notFound');

        $context = (new Workflow())(
            $definition->config(),
            $this->step([self::A => [$reject]], $calls),
            new ProcessContext(new \stdClass())
        );

        $this->assertSame([self::A], $calls);
        $this->assertSame($reject, $context->previous());
    }

    public function testRevisitedNodeRunsAgainAndIterationCounts(): void
    {
        $definition = ProcessDefinition::for('In', 'P')
            ->node(self::A)->onTrue(self::B)
            ->node(self::B)->onFalse(self::A)->onTrue(self::C)
            ->node(self::C)->terminal()
            ->build();
        $iterations = [];
        $step = static function (string $node, ProcessContext $context) use (&$iterations): NodeResult {
            $iterations[] = [$node, $context->iteration($node)];
            if ($node === self::B && $context->iteration(self::B) < 2) {
                return NodeResult::route(Route::OnFalse);
            }

            return NodeResult::ok([]);
        };

        $context = (new Workflow())($definition->config(), $step, new ProcessContext(new \stdClass()));

        $this->assertSame([
            [self::A, 0], [self::B, 0], [self::A, 1], [self::B, 1], [self::A, 2], [self::B, 2], [self::C, 0],
        ], $iterations);
        $this->assertCount(2 + 2 + 2 + 1, $context->chain());
        $this->assertCount(3, $context->all(self::A));
    }

    public function testEachStepGetsAFreshContextAndTheStartContextStaysUntouched(): void
    {
        $definition = ProcessDefinition::for('In', 'P')
            ->node(self::A)->onTrue(self::B)
            ->node(self::B)->terminal()
            ->build();
        $start = new ProcessContext(new \stdClass());
        $contexts = [];
        $step = static function (string $node, ProcessContext $context) use (&$contexts): NodeResult {
            $contexts[$node] = $context;

            return NodeResult::ok([]);
        };

        $end = (new Workflow())($definition->config(), $step, $start);

        $this->assertSame([], $start->chain());
        $this->assertCount(0, $contexts[self::A]->chain());
        $this->assertCount(1, $contexts[self::B]->chain());
        $this->assertCount(2, $end->chain());
        $this->assertNotSame($start, $end);
    }

    public function testContextSubclassSurvivesTheRun(): void
    {
        $definition = ProcessDefinition::for('In', 'P')->node(self::A)->terminal()->build();
        $start = new class (new \stdClass()) extends ProcessContext {
        };
        $step = static fn (string $node, ProcessContext $context): NodeResult => NodeResult::ok([]);

        $end = (new Workflow())($definition->config(), $step, $start);

        $this->assertInstanceOf($start::class, $end);
    }

    public function testStepFailureIsNotSwallowed(): void
    {
        $definition = ProcessDefinition::for('In', 'P')->node(self::A)->terminal()->build();
        $step = static function (string $node, ProcessContext $context): NodeResult {
            throw new \DomainException('node blew up');
        };

        $this->expectException(\DomainException::class);

        (new Workflow())($definition->config(), $step, new ProcessContext(new \stdClass()));
    }
}
