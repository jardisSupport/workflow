<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Tests\Unit\Builder;

use InvalidArgumentException;
use JardisSupport\Workflow\Builder\WorkflowBuilder;
use JardisSupport\Workflow\Builder\WorkflowNodeBuilder;
use JardisSupport\Workflow\WorkflowConfig;
use JardisSupport\Workflow\WorkflowResult;
use PHPUnit\Framework\TestCase;

/**
 * Unit Tests for WorkflowBuilder
 *
 * Tests fluent workflow configuration building
 */
class WorkflowBuilderTest extends TestCase
{
    public function testConstructorReturnsNewInstance(): void
    {
        $builder = new WorkflowBuilder();

        $this->assertInstanceOf(WorkflowBuilder::class, $builder);
    }

    public function testNodeReturnsNodeBuilder(): void
    {
        $builder = new WorkflowBuilder();

        $nodeBuilder = $builder->node(BuilderTestHandler::class);

        $this->assertInstanceOf(WorkflowNodeBuilder::class, $nodeBuilder);
    }

    public function testBuildReturnsWorkflowConfig(): void
    {
        $config = (new WorkflowBuilder())
            ->node(BuilderTestHandler::class)
            ->build();

        $this->assertInstanceOf(WorkflowConfig::class, $config);
    }

    public function testBuildThrowsExceptionWhenNoNodes(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('WorkflowBuilder requires at least one node');

        (new WorkflowBuilder())->build();
    }

    public function testSingleNodeWithSuccessTransition(): void
    {
        /** @var WorkflowConfig $config */
        $config = (new WorkflowBuilder())
            ->node(BuilderTestHandler::class)
                ->onSuccess(BuilderTestSuccessHandler::class)
            ->build();

        $nodes = $config->getNodes();

        $this->assertCount(1, $nodes);
        $this->assertSame(BuilderTestHandler::class, $nodes[0]['handler']);
        $this->assertSame(
            BuilderTestSuccessHandler::class,
            $nodes[0]['transitions'][WorkflowResult::ON_SUCCESS]
        );
    }

    public function testSingleNodeWithMultipleTransitions(): void
    {
        /** @var WorkflowConfig $config */
        $config = (new WorkflowBuilder())
            ->node(BuilderTestHandler::class)
                ->onSuccess(BuilderTestSuccessHandler::class)
                ->onFail(BuilderTestFailHandler::class)
            ->build();

        $transitions = $config->getTransitions(BuilderTestHandler::class);

        $this->assertNotNull($transitions);
        $this->assertSame(BuilderTestSuccessHandler::class, $transitions[WorkflowResult::ON_SUCCESS]);
        $this->assertSame(BuilderTestFailHandler::class, $transitions[WorkflowResult::ON_FAIL]);
    }

    public function testMultipleNodesWithTransitions(): void
    {
        $config = (new WorkflowBuilder())
            ->node(BuilderTestHandler::class)
                ->onSuccess(BuilderTestSuccessHandler::class)
                ->onFail(BuilderTestFailHandler::class)
            ->node(BuilderTestSuccessHandler::class)
                ->onSuccess(BuilderTestFailHandler::class)
            ->build();

        $nodes = $config->getNodes();

        $this->assertCount(2, $nodes);
        $this->assertSame(BuilderTestHandler::class, $nodes[0]['handler']);
        $this->assertSame(BuilderTestSuccessHandler::class, $nodes[1]['handler']);
    }

    public function testAllTransitionMethods(): void
    {
        /** @var WorkflowConfig $config */
        $config = (new WorkflowBuilder())
            ->node(BuilderTestHandler::class)
                ->onSuccess(BuilderTestSuccessHandler::class)
                ->onFail(BuilderTestFailHandler::class)
                ->onError(BuilderTestSuccessHandler::class)
                ->onTimeout(BuilderTestFailHandler::class)
                ->onRetry(BuilderTestHandler::class)
                ->onSkip(BuilderTestSuccessHandler::class)
                ->onPending(BuilderTestFailHandler::class)
                ->onCancel(BuilderTestSuccessHandler::class)
            ->build();

        $transitions = $config->getTransitions(BuilderTestHandler::class);

        $this->assertNotNull($transitions);
        $this->assertSame(BuilderTestSuccessHandler::class, $transitions[WorkflowResult::ON_SUCCESS]);
        $this->assertSame(BuilderTestFailHandler::class, $transitions[WorkflowResult::ON_FAIL]);
        $this->assertSame(BuilderTestSuccessHandler::class, $transitions[WorkflowResult::ON_ERROR]);
        $this->assertSame(BuilderTestFailHandler::class, $transitions[WorkflowResult::ON_TIMEOUT]);
        $this->assertSame(BuilderTestHandler::class, $transitions[WorkflowResult::ON_RETRY]);
        $this->assertSame(BuilderTestSuccessHandler::class, $transitions[WorkflowResult::ON_SKIP]);
        $this->assertSame(BuilderTestFailHandler::class, $transitions[WorkflowResult::ON_PENDING]);
        $this->assertSame(BuilderTestSuccessHandler::class, $transitions[WorkflowResult::ON_CANCEL]);
    }

    public function testNodeWithoutTransitionsIsValid(): void
    {
        /** @var WorkflowConfig $config */
        $config = (new WorkflowBuilder())
            ->node(BuilderTestHandler::class)
            ->build();

        $nodes = $config->getNodes();

        $this->assertCount(1, $nodes);
        $this->assertSame([], $nodes[0]['transitions']);
    }

    public function testInvalidHandlerClassThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('does not exist');

        (new WorkflowBuilder())
            ->node('NonExistent\\Handler\\Class');
    }

    public function testComplexWorkflowChain(): void
    {
        /** @var WorkflowConfig $config */
        $config = (new WorkflowBuilder())
            ->node(BuilderTestHandler::class)
                ->onSuccess(BuilderTestSuccessHandler::class)
                ->onFail(BuilderTestFailHandler::class)
                ->onRetry(BuilderTestHandler::class)
            ->node(BuilderTestSuccessHandler::class)
                ->onSuccess(BuilderTestFailHandler::class)
            ->node(BuilderTestFailHandler::class)
            ->build();

        $nodes = $config->getNodes();

        $this->assertCount(3, $nodes);

        $transitions1 = $config->getTransitions(BuilderTestHandler::class);
        $this->assertSame(BuilderTestSuccessHandler::class, $transitions1[WorkflowResult::ON_SUCCESS]);
        $this->assertSame(BuilderTestFailHandler::class, $transitions1[WorkflowResult::ON_FAIL]);
        $this->assertSame(BuilderTestHandler::class, $transitions1[WorkflowResult::ON_RETRY]);

        $transitions2 = $config->getTransitions(BuilderTestSuccessHandler::class);
        $this->assertSame(BuilderTestFailHandler::class, $transitions2[WorkflowResult::ON_SUCCESS]);

        $transitions3 = $config->getTransitions(BuilderTestFailHandler::class);
        $this->assertEmpty($transitions3);
    }

    public function testNodeBuilderReturnsNodeBuilderOnTransitions(): void
    {
        $builder = new WorkflowBuilder();
        $nodeBuilder = $builder->node(BuilderTestHandler::class);

        $result = $nodeBuilder->onSuccess(BuilderTestSuccessHandler::class);

        $this->assertSame($nodeBuilder, $result);
    }

    public function testNodeBuilderNodeMethodReturnsNewNodeBuilder(): void
    {
        $builder = new WorkflowBuilder();
        $nodeBuilder1 = $builder->node(BuilderTestHandler::class);
        $nodeBuilder2 = $nodeBuilder1->node(BuilderTestSuccessHandler::class);

        $this->assertInstanceOf(WorkflowNodeBuilder::class, $nodeBuilder2);
        $this->assertNotSame($nodeBuilder1, $nodeBuilder2);
    }
}

/**
 * Test handler classes for WorkflowBuilder tests
 */
class BuilderTestHandler
{
    public function __invoke(): void
    {
    }
}

class BuilderTestSuccessHandler
{
    public function __invoke(): void
    {
    }
}

class BuilderTestFailHandler
{
    public function __invoke(): void
    {
    }
}
