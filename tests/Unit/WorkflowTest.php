<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Tests\Unit;

use InvalidArgumentException;
use JardisSupport\Contract\Workflow\WorkflowContextInterface;
use JardisSupport\Contract\Workflow\WorkflowResultInterface;
use JardisSupport\Workflow\Builder\WorkflowBuilder;
use JardisSupport\Workflow\Workflow;
use JardisSupport\Workflow\WorkflowConfig;
use JardisSupport\Workflow\WorkflowContext;
use JardisSupport\Workflow\WorkflowResult;
use PHPUnit\Framework\TestCase;

/**
 * Unit Tests for Workflow
 *
 * Verifies handler chain execution, context propagation, transition routing,
 * the handler-factory $data forwarding contract, the single-context output,
 * and the R5 routing-safety guard (routing to unregistered nodes is a no-op).
 */
class WorkflowTest extends TestCase
{
    public function testInvokeWithEmptyConfigReturnsEmptyContext(): void
    {
        $workflow = new Workflow();
        $config = new WorkflowConfig();

        $context = $workflow($config);

        $this->assertInstanceOf(WorkflowContextInterface::class, $context);
        $this->assertSame([], $context->getChain());
        $this->assertNull($context->getPrevious());
    }

    public function testInvokeAppendsToProvidedContextAndReturnsIt(): void
    {
        $workflow = new Workflow();

        $config = new WorkflowConfig();
        $config->addNode(SuccessfulHandler::class, [
            WorkflowResult::ON_SUCCESS => SecondHandler::class,
        ]);
        $config->addNode(SecondHandler::class);

        $provided = new WorkflowContext();

        $returned = $workflow($config, null, $provided);

        // Engine used the provided context and returned the very same instance.
        $this->assertSame($provided, $returned);
        $this->assertCount(2, $provided->getChain());
        $this->assertNotNull($provided->getLatest(SuccessfulHandler::class));
        $this->assertNotNull($provided->getLatest(SecondHandler::class));
    }

    public function testInvokeWithoutContextCreatesAFreshContextPerRun(): void
    {
        $workflow = new Workflow();
        $config = new WorkflowConfig();
        $config->addNode(SuccessfulHandler::class);

        $first = $workflow($config);
        $second = $workflow($config);

        // Default path unchanged: a fresh WorkflowContext per run, never shared.
        $this->assertInstanceOf(WorkflowContext::class, $first);
        $this->assertInstanceOf(WorkflowContext::class, $second);
        $this->assertNotSame($first, $second);
    }

    public function testInvokeExecutesSingleNodeHandler(): void
    {
        $workflow = new Workflow();

        $config = new WorkflowConfig();
        $config->addNode(SuccessfulHandler::class);

        $context = $workflow($config);

        $result = $context->getLatest(SuccessfulHandler::class);
        $this->assertInstanceOf(WorkflowResult::class, $result);
        $this->assertSame(['status' => 'success'], $result->getData());
    }

    public function testInvokeFollowsSuccessPath(): void
    {
        $workflow = new Workflow();

        $config = new WorkflowConfig();
        $config->addNode(SuccessfulHandler::class, [
            WorkflowResult::ON_SUCCESS => SecondHandler::class,
        ]);
        $config->addNode(SecondHandler::class);

        $context = $workflow($config);

        $this->assertNotNull($context->getLatest(SuccessfulHandler::class));
        $this->assertNotNull($context->getLatest(SecondHandler::class));
        $this->assertCount(2, $context->getChain());
    }

    public function testInvokeFollowsFailPath(): void
    {
        $workflow = new Workflow();

        $config = new WorkflowConfig();
        $config->addNode(FailingHandler::class, [
            WorkflowResult::ON_SUCCESS => SuccessfulHandler::class,
            WorkflowResult::ON_FAIL => ErrorHandler::class,
        ]);
        $config->addNode(SuccessfulHandler::class);
        $config->addNode(ErrorHandler::class);

        $context = $workflow($config);

        $this->assertNotNull($context->getLatest(FailingHandler::class));
        $this->assertNotNull($context->getLatest(ErrorHandler::class));
        $this->assertNull($context->getLatest(SuccessfulHandler::class));
    }

    public function testInvokeMakesEachHandlerResultAddressable(): void
    {
        $workflow = new Workflow();

        $config = new WorkflowConfig();
        $config->addNode(FirstResultHandler::class, [
            WorkflowResult::ON_SUCCESS => SecondResultHandler::class,
        ]);
        $config->addNode(SecondResultHandler::class);

        $context = $workflow($config);

        $first = $context->getLatest(FirstResultHandler::class);
        $second = $context->getLatest(SecondResultHandler::class);

        $this->assertSame(['first' => 'value1'], $first?->getData());
        $this->assertSame(['second' => 'value2'], $second?->getData());
    }

    public function testHandlerReceivesContextAsItsSingleArgument(): void
    {
        $workflow = new Workflow();

        $config = new WorkflowConfig();
        $config->addNode(ContextSlotHandler::class);

        $context = $workflow($config);

        $data = $context->getLatest(ContextSlotHandler::class)?->getData();
        $this->assertIsArray($data);
        $this->assertTrue($data['contextIsSoleArg']);
        $this->assertInstanceOf(WorkflowContext::class, $data['contextInstance']);
    }

    public function testDataIsForwardedToHandlerFactory(): void
    {
        $captured = ['classes' => [], 'data' => []];
        $factory = function (string $class, mixed $data) use (&$captured): object {
            $captured['classes'][] = $class;
            $captured['data'][] = $data;
            return new $class();
        };

        $workflow = new Workflow($factory);

        $config = new WorkflowConfig();
        $config->addNode(SuccessfulHandler::class, [
            WorkflowResult::ON_SUCCESS => SecondHandler::class,
        ]);
        $config->addNode(SecondHandler::class);

        $payload = (object) ['orderId' => 42];
        $workflow($config, $payload);

        $this->assertSame(
            [SuccessfulHandler::class, SecondHandler::class],
            $captured['classes'],
        );
        $this->assertSame([$payload, $payload], $captured['data']);
    }

    public function testFactoryReceivesNullDataWhenNoDataIsPassed(): void
    {
        $receivedData = 'sentinel';
        $factory = function (string $class, mixed $data) use (&$receivedData): object {
            $receivedData = $data;
            return new $class();
        };

        $workflow = new Workflow($factory);

        $config = new WorkflowConfig();
        $config->addNode(SuccessfulHandler::class);

        $workflow($config);

        $this->assertNull($receivedData);
    }

    public function testInvokeStopsWhenNoNextHandler(): void
    {
        $workflow = new Workflow();

        $config = new WorkflowConfig();
        $config->addNode(SuccessfulHandler::class);

        $context = $workflow($config);

        $this->assertCount(1, $context->getChain());
    }

    public function testInvokeThrowsExceptionWhenHandlerNotCallable(): void
    {
        $workflow = new Workflow();

        $config = new WorkflowConfig();
        $config->addNode(NonCallableHandler::class);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Workflow handler');

        $workflow($config);
    }

    public function testChainEntriesAreStampedWithFullyQualifiedHandlerName(): void
    {
        $workflow = new Workflow();

        $config = new WorkflowConfig();
        $config->addNode(SuccessfulHandler::class);

        $context = $workflow($config);

        $chain = $context->getChain();
        $this->assertCount(1, $chain);
        $this->assertSame(SuccessfulHandler::class, $chain[0]->getHandlerFqcn());
        $this->assertNotSame('SuccessfulHandler', $chain[0]->getHandlerFqcn());
    }

    public function testInvokeWithComplexWorkflowPath(): void
    {
        $workflow = new Workflow();

        $config = new WorkflowConfig();
        $config->addNode(FirstResultHandler::class, [
            WorkflowResult::ON_SUCCESS => SecondResultHandler::class,
            WorkflowResult::ON_FAIL => ErrorHandler::class,
        ]);
        $config->addNode(SecondResultHandler::class, [
            WorkflowResult::ON_SUCCESS => ThirdResultHandler::class,
        ]);
        $config->addNode(ThirdResultHandler::class);
        $config->addNode(ErrorHandler::class);

        $context = $workflow($config);

        $this->assertCount(3, $context->getChain());
        $this->assertNotNull($context->getLatest(FirstResultHandler::class));
        $this->assertNotNull($context->getLatest(SecondResultHandler::class));
        $this->assertNotNull($context->getLatest(ThirdResultHandler::class));
        $this->assertNull($context->getLatest(ErrorHandler::class));
    }

    public function testHandlerSeesPreviousResultViaContext(): void
    {
        $workflow = new Workflow();

        $config = new WorkflowConfig();
        $config->addNode(FirstResultHandler::class, [
            WorkflowResult::ON_SUCCESS => PreviousInspectorHandler::class,
        ]);
        $config->addNode(PreviousInspectorHandler::class);

        $context = $workflow($config);

        $data = $context->getLatest(PreviousInspectorHandler::class)?->getData();
        $this->assertTrue($data['hadPrevious']);
        $this->assertSame(['first' => 'value1'], $data['previousData']);
    }

    public function testFirstHandlerSeesNullPrevious(): void
    {
        $workflow = new Workflow();

        $config = new WorkflowConfig();
        $config->addNode(PreviousInspectorHandler::class);

        $context = $workflow($config);

        $data = $context->getLatest(PreviousInspectorHandler::class)?->getData();
        $this->assertFalse($data['hadPrevious']);
    }

    public function testGetPreviousReturnsLastExecutedResult(): void
    {
        $workflow = new Workflow();

        $config = new WorkflowConfig();
        $config->addNode(FirstResultHandler::class, [
            WorkflowResult::ON_SUCCESS => SecondResultHandler::class,
        ]);
        $config->addNode(SecondResultHandler::class);

        $context = $workflow($config);

        $this->assertSame(['second' => 'value2'], $context->getPrevious()?->getData());
    }

    public function testInvokeThrowsExceptionForNonWorkflowResultReturn(): void
    {
        $workflow = new Workflow();

        $config = new WorkflowConfig();
        $config->addNode(InvalidReturnHandler::class);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must return a WorkflowResultInterface instance');

        $workflow($config);
    }

    public function testChainEntriesAreWorkflowResultInstances(): void
    {
        $workflow = new Workflow();

        $config = new WorkflowConfig();
        $config->addNode(SuccessfulHandler::class, [
            WorkflowResult::ON_SUCCESS => SecondHandler::class,
        ]);
        $config->addNode(SecondHandler::class);

        $context = $workflow($config);

        foreach ($context->getChain() as $entry) {
            $this->assertInstanceOf(
                WorkflowResult::class,
                $entry,
                "Chain entry for '{$entry->getHandlerFqcn()}' should be a WorkflowResult"
            );
        }
    }

    public function testWorkflowResultSuccessIsUsedForRouting(): void
    {
        $workflow = new Workflow();

        $config = new WorkflowConfig();
        $config->addNode(SuccessfulHandler::class, [
            WorkflowResult::ON_SUCCESS => SecondHandler::class,
            WorkflowResult::ON_FAIL => ErrorHandler::class,
        ]);
        $config->addNode(SecondHandler::class);
        $config->addNode(ErrorHandler::class);

        $context = $workflow($config);

        $this->assertSame(
            WorkflowResult::ON_SUCCESS,
            $context->getLatest(SuccessfulHandler::class)?->getStatus()
        );
        $this->assertNotNull($context->getLatest(SecondHandler::class));
        $this->assertNull($context->getLatest(ErrorHandler::class));
    }

    public function testWorkflowResultFailIsUsedForRouting(): void
    {
        $workflow = new Workflow();

        $config = new WorkflowConfig();
        $config->addNode(FailingHandler::class, [
            WorkflowResult::ON_SUCCESS => SuccessfulHandler::class,
            WorkflowResult::ON_FAIL => ErrorHandler::class,
        ]);
        $config->addNode(SuccessfulHandler::class);
        $config->addNode(ErrorHandler::class);

        $context = $workflow($config);

        $this->assertSame(
            WorkflowResult::ON_FAIL,
            $context->getLatest(FailingHandler::class)?->getStatus()
        );
        $this->assertNotNull($context->getLatest(ErrorHandler::class));
        $this->assertNull($context->getLatest(SuccessfulHandler::class));
    }

    public function testEmptyArrayDataIsStillSuccess(): void
    {
        $workflow = new Workflow();

        $config = new WorkflowConfig();
        $config->addNode(EmptySuccessHandler::class, [
            WorkflowResult::ON_SUCCESS => SecondHandler::class,
            WorkflowResult::ON_FAIL => ErrorHandler::class,
        ]);
        $config->addNode(SecondHandler::class);
        $config->addNode(ErrorHandler::class);

        $context = $workflow($config);

        $this->assertNotNull($context->getLatest(SecondHandler::class));
        $this->assertNull($context->getLatest(ErrorHandler::class));
    }

    public function testWorkflowFollowsSkipTransition(): void
    {
        $workflow = new Workflow();

        $config = new WorkflowConfig();
        $config->addNode(SkipEmittingHandler::class, [
            WorkflowResult::ON_SUCCESS => SuccessfulHandler::class,
            WorkflowResult::ON_FAIL => ErrorHandler::class,
            WorkflowResult::ON_SKIP => SkipTargetHandler::class,
        ]);
        $config->addNode(SuccessfulHandler::class);
        $config->addNode(ErrorHandler::class);
        $config->addNode(SkipTargetHandler::class);

        $context = $workflow($config);

        $this->assertNotNull($context->getLatest(SkipEmittingHandler::class));
        $this->assertNotNull($context->getLatest(SkipTargetHandler::class));
        $this->assertNull($context->getLatest(SuccessfulHandler::class));
        $this->assertNull($context->getLatest(ErrorHandler::class));
    }

    public function testWorkflowFollowsEventTransition(): void
    {
        $workflow = new Workflow();

        $config = new WorkflowConfig();
        $config->addNode(EventEmittingHandler::class, [
            WorkflowResult::ON_SUCCESS => SuccessfulHandler::class,
            WorkflowResult::ON_EVENT => EventTargetHandler::class,
        ]);
        $config->addNode(SuccessfulHandler::class);
        $config->addNode(EventTargetHandler::class);

        $context = $workflow($config);

        $this->assertNotNull($context->getLatest(EventEmittingHandler::class));
        $this->assertNotNull($context->getLatest(EventTargetHandler::class));
        $this->assertNull($context->getLatest(SuccessfulHandler::class));
    }

    public function testWorkflowStopsWhenNamedTransitionNotConfigured(): void
    {
        $workflow = new Workflow();

        $config = new WorkflowConfig();
        $config->addNode(SkipEmittingHandler::class, [
            WorkflowResult::ON_SUCCESS => SuccessfulHandler::class,
        ]);
        $config->addNode(SuccessfulHandler::class);

        $context = $workflow($config);

        $this->assertCount(1, $context->getChain());
        $this->assertNotNull($context->getLatest(SkipEmittingHandler::class));
    }

    public function testWorkflowWithCustomHandlerFactory(): void
    {
        $factoryCalled = false;
        $factory = function (string $class, mixed $data) use (&$factoryCalled): object {
            $factoryCalled = true;
            return new $class();
        };

        $workflow = new Workflow($factory);

        $config = new WorkflowConfig();
        $config->addNode(SuccessfulHandler::class);

        $workflow($config);

        $this->assertTrue($factoryCalled);
    }

    public function testLoopPreservesAllInvocationsInChain(): void
    {
        $workflow = new Workflow();

        $config = new WorkflowConfig();
        // CountingHandler fails twice (ON_FAIL self-loop), then succeeds — three invocations total
        $config->addNode(CountingHandler::class, [
            WorkflowResult::ON_SUCCESS => SecondHandler::class,
            WorkflowResult::ON_FAIL => CountingHandler::class,
        ]);
        $config->addNode(SecondHandler::class);

        $context = $workflow($config);

        $allAttempts = $context->getAll(CountingHandler::class);
        $this->assertCount(3, $allAttempts);
        $this->assertSame(['attempt' => 1], $allAttempts[0]->getData());
        $this->assertSame(['attempt' => 2], $allAttempts[1]->getData());
        $this->assertSame(['attempt' => 3], $allAttempts[2]->getData());
        $this->assertCount(4, $context->getChain());
        $this->assertNotNull($context->getLatest(SecondHandler::class));
    }

    public function testEngineReturnsWhenTransitionTargetIsNotARegisteredNode(): void
    {
        // SuccessfulHandler's onSuccess points to SecondHandler, but SecondHandler is NOT addNode()'d.
        $config = (new WorkflowBuilder())
            ->node(SuccessfulHandler::class)
                ->onSuccess(SecondHandler::class)
            ->build();
        // Note: only SuccessfulHandler is registered as a node.

        $workflow = new Workflow();
        $context = $workflow($config);

        // Engine ran SuccessfulHandler once and returned cleanly. SecondHandler was never invoked.
        $this->assertCount(1, $context->getChain());
        $this->assertInstanceOf(
            WorkflowResultInterface::class,
            $context->getLatest(SuccessfulHandler::class)
        );
        $this->assertNull($context->getLatest(SecondHandler::class));
    }

    public function testEngineFollowsTransitionWhenTargetIsRegisteredNode(): void
    {
        // Same wiring as above, but SecondHandler is now registered.
        $config = (new WorkflowBuilder())
            ->node(SuccessfulHandler::class)
                ->onSuccess(SecondHandler::class)
            ->node(SecondHandler::class)
            ->build();

        $workflow = new Workflow();
        $context = $workflow($config);

        $this->assertCount(2, $context->getChain());
    }

    public function testEngineReturnsWhenOnEventTransitionMissing(): void
    {
        // Handler returns ON_EVENT, but no onEvent transition is configured.
        $config = (new WorkflowBuilder())
            ->node(EventEmittingHandler::class)
            ->build();

        $workflow = new Workflow();
        $context = $workflow($config);

        $this->assertCount(1, $context->getChain());
        $result = $context->getLatest(EventEmittingHandler::class);
        $this->assertSame(WorkflowResult::ON_EVENT, $result?->getStatus());
    }
}

/**
 * Test Handlers — all share the single-arg signature __invoke(WorkflowContext).
 */
class SuccessfulHandler
{
    public function __invoke(WorkflowContext $context): WorkflowResult
    {
        return new WorkflowResult(WorkflowResult::ON_SUCCESS, ['status' => 'success']);
    }
}

class FailingHandler
{
    public function __invoke(WorkflowContext $context): WorkflowResult
    {
        return new WorkflowResult(WorkflowResult::ON_FAIL, ['reason' => 'validation_error']);
    }
}

class ErrorHandler
{
    public function __invoke(WorkflowContext $context): WorkflowResult
    {
        return new WorkflowResult(WorkflowResult::ON_SUCCESS, ['error' => 'handled']);
    }
}

class SecondHandler
{
    public function __invoke(WorkflowContext $context): WorkflowResult
    {
        return new WorkflowResult(WorkflowResult::ON_SUCCESS, ['step' => 'second']);
    }
}

class FirstResultHandler
{
    public function __invoke(WorkflowContext $context): WorkflowResult
    {
        return new WorkflowResult(WorkflowResult::ON_SUCCESS, ['first' => 'value1']);
    }
}

class SecondResultHandler
{
    public function __invoke(WorkflowContext $context): WorkflowResult
    {
        return new WorkflowResult(WorkflowResult::ON_SUCCESS, ['second' => 'value2']);
    }
}

class ThirdResultHandler
{
    public function __invoke(WorkflowContext $context): WorkflowResult
    {
        return new WorkflowResult(WorkflowResult::ON_SUCCESS, ['third' => 'value3']);
    }
}

class ContextSlotHandler
{
    public function __invoke(WorkflowContext $context): WorkflowResult
    {
        return new WorkflowResult(WorkflowResult::ON_SUCCESS, [
            'contextIsSoleArg' => true,
            'contextInstance' => $context,
        ]);
    }
}

class NonCallableHandler
{
    // No __invoke method - not callable
}

class PreviousInspectorHandler
{
    public function __invoke(WorkflowContext $context): WorkflowResult
    {
        $previous = $context->getPrevious();

        return new WorkflowResult(WorkflowResult::ON_SUCCESS, [
            'hadPrevious' => $previous !== null,
            'previousData' => $previous?->getData(),
        ]);
    }
}

class InvalidReturnHandler
{
    /** @return array<string, string> */
    public function __invoke(WorkflowContext $context): array
    {
        return ['this' => 'is not a WorkflowResult'];
    }
}

class EmptySuccessHandler
{
    public function __invoke(WorkflowContext $context): WorkflowResult
    {
        return new WorkflowResult(WorkflowResult::ON_SUCCESS, []);
    }
}

class SkipEmittingHandler
{
    public function __invoke(WorkflowContext $context): WorkflowResult
    {
        return new WorkflowResult(WorkflowResult::ON_SKIP, ['reason' => 'not_applicable']);
    }
}

class SkipTargetHandler
{
    public function __invoke(WorkflowContext $context): WorkflowResult
    {
        return new WorkflowResult(WorkflowResult::ON_SUCCESS, ['skipped' => true]);
    }
}

class EventEmittingHandler
{
    public function __invoke(WorkflowContext $context): WorkflowResult
    {
        return new WorkflowResult(WorkflowResult::ON_EVENT, ['eventName' => 'TestDispatched']);
    }
}

class EventTargetHandler
{
    public function __invoke(WorkflowContext $context): WorkflowResult
    {
        return new WorkflowResult(WorkflowResult::ON_SUCCESS, ['awaiting' => true]);
    }
}

class CountingHandler
{
    public function __invoke(WorkflowContext $context): WorkflowResult
    {
        $previousAttempts = $context->getAll(self::class);
        $attempt = count($previousAttempts) + 1;

        if ($attempt < 3) {
            return new WorkflowResult(WorkflowResult::ON_FAIL, ['attempt' => $attempt]);
        }

        return new WorkflowResult(WorkflowResult::ON_SUCCESS, ['attempt' => $attempt]);
    }
}
