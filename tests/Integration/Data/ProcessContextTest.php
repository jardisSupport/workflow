<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Tests\Integration\Data;

use JardisSupport\Workflow\Data\NodeResult;
use JardisSupport\Workflow\Data\ProcessContext;
use JardisSupport\Workflow\Data\Route;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * ProcessContext: getters over an immutable chain; withStep clones.
 */
class ProcessContextTest extends TestCase
{
    public function testFreshContextExposesInputActorAndEmptyChain(): void
    {
        $input = new \stdClass();
        $actor = new \stdClass();

        $context = new ProcessContext($input, $actor);

        $this->assertSame($input, $context->input());
        $this->assertSame($actor, $context->actor());
        $this->assertNull($context->previous());
        $this->assertNull($context->latest('Node\\A'));
        $this->assertSame([], $context->all('Node\\A'));
        $this->assertSame(0, $context->iteration('Node\\A'));
        $this->assertSame([], $context->chain());
        $this->assertSame(0, $context->depth());
    }

    public function testActorDefaultsToNullAndDepthIsCarried(): void
    {
        $context = new ProcessContext(new \stdClass(), null, 3);

        $this->assertNull($context->actor());
        $this->assertSame(3, $context->depth());
    }

    public function testWithStepReturnsAClonedContextAndLeavesTheOriginalUnchanged(): void
    {
        $original = new ProcessContext(new \stdClass());
        $result = NodeResult::ok(['n' => 1]);

        $next = $original->withStep('Node\\A', $result);

        $this->assertNotSame($original, $next);
        $this->assertSame([], $original->chain());
        $this->assertNull($original->previous());
        $this->assertSame($result, $next->previous());
        $this->assertSame([['node' => 'Node\\A', 'result' => $result]], $next->chain());
    }

    public function testWithStepKeepsInputActorAndDepth(): void
    {
        $input = new \stdClass();
        $actor = new \stdClass();

        $next = (new ProcessContext($input, $actor, 2))->withStep('Node\\A', NodeResult::ok([]));

        $this->assertSame($input, $next->input());
        $this->assertSame($actor, $next->actor());
        $this->assertSame(2, $next->depth());
    }

    public function testChainLookupsFollowExecutionOrderAcrossRevisits(): void
    {
        $first = NodeResult::route(Route::OnFalse, 'first');
        $other = NodeResult::ok(['v' => 'other']);
        $second = NodeResult::ok(['v' => 'second']);

        $context = (new ProcessContext(new \stdClass()))
            ->withStep('Node\\A', $first)
            ->withStep('Node\\B', $other)
            ->withStep('Node\\A', $second);

        $this->assertSame($second, $context->previous());
        $this->assertSame($second, $context->latest('Node\\A'));
        $this->assertSame($other, $context->latest('Node\\B'));
        $this->assertSame([$first, $second], $context->all('Node\\A'));
        $this->assertSame(2, $context->iteration('Node\\A'));
        $this->assertSame(1, $context->iteration('Node\\B'));
        $this->assertCount(3, $context->chain());
        $this->assertNull($context->latest('Node\\C'));
    }

    public function testNoPublicMeansToMutateTheChainBesidesTheInternalWithStep(): void
    {
        $reflection = new ReflectionClass(ProcessContext::class);

        $this->assertSame([], array_filter(
            $reflection->getProperties(\ReflectionProperty::IS_PUBLIC),
            static fn ($p): bool => !$p->isReadOnly()
        ));

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isConstructor() || $method->getName() === 'withStep') {
                continue;
            }
            $this->assertDoesNotMatchRegularExpression(
                '/^(set|append|add|with|push|remove)/',
                $method->getName(),
                'public mutator found: ' . $method->getName()
            );
        }

        $withStep = $reflection->getMethod('withStep');
        $this->assertStringContainsString('@internal', (string) $withStep->getDocComment());
    }

    public function testClassIsNotFinalSoGeneratedContextsCanExtendIt(): void
    {
        $this->assertFalse((new ReflectionClass(ProcessContext::class))->isFinal());
    }

    public function testWithStepOnASubclassKeepsTheSubclass(): void
    {
        $context = new class (new \stdClass()) extends ProcessContext {
        };

        $this->assertInstanceOf($context::class, $context->withStep('Node\\A', NodeResult::ok([])));
    }
}
