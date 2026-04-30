<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Tests\Unit;

use InvalidArgumentException;
use JardisSupport\Contract\Workflow\WorkflowContextInterface;
use JardisSupport\Workflow\Workflow;
use JardisSupport\Workflow\WorkflowConfig;
use JardisSupport\Workflow\WorkflowContext;
use JardisSupport\Workflow\WorkflowResult;
use PHPUnit\Framework\TestCase;

/**
 * Unit Tests for Workflow
 *
 * Verifies handler chain execution, context propagation and transition routing.
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

    public function testInvokePassesParametersToHandlers(): void
    {
        $workflow = new Workflow();

        $config = new WorkflowConfig();
        $config->addNode(ParameterEchoHandler::class);

        $context = $workflow($config, 'param1', 'param2');

        $data = $context->getLatest(ParameterEchoHandler::class)?->getData();
        $this->assertIsArray($data);
        $this->assertSame(['param1', 'param2'], $data['initial']);
    }

    public function testContextIsAppendedAsLastParameter(): void
    {
        $workflow = new Workflow();

        $config = new WorkflowConfig();
        $config->addNode(ContextSlotHandler::class);

        $context = $workflow($config, 'subject');

        $data = $context->getLatest(ContextSlotHandler::class)?->getData();
        $this->assertTrue($data['contextWasLastParam']);
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

    public function testChainEntriesIdentifyHandlersByFullyQualifiedClassName(): void
    {
        $workflow = new Workflow();

        $config = new WorkflowConfig();
        $config->addNode(SuccessfulHandler::class);

        $context = $workflow($config);

        $chain = $context->getChain();
        $this->assertCount(1, $chain);
        $this->assertSame(SuccessfulHandler::class, $chain[0]['handler']);
        $this->assertNotSame('SuccessfulHandler', $chain[0]['handler']);
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
                $entry['result'],
                "Chain entry for '{$entry['handler']}' should carry a WorkflowResult"
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

        $this->assertTrue($context->getLatest(SuccessfulHandler::class)?->isSuccess());
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

        $this->assertTrue($context->getLatest(FailingHandler::class)?->isFail());
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

    public function testWorkflowFollowsNamedTransition(): void
    {
        $workflow = new Workflow();

        $config = new WorkflowConfig();
        $config->addNode(RetryHandler::class, [
            WorkflowResult::ON_SUCCESS => SuccessfulHandler::class,
            WorkflowResult::ON_FAIL => ErrorHandler::class,
            WorkflowResult::ON_RETRY => RetryTargetHandler::class,
        ]);
        $config->addNode(SuccessfulHandler::class);
        $config->addNode(ErrorHandler::class);
        $config->addNode(RetryTargetHandler::class);

        $context = $workflow($config);

        $this->assertNotNull($context->getLatest(RetryHandler::class));
        $this->assertNotNull($context->getLatest(RetryTargetHandler::class));
        $this->assertNull($context->getLatest(SuccessfulHandler::class));
        $this->assertNull($context->getLatest(ErrorHandler::class));
    }

    public function testWorkflowFollowsPendingTransition(): void
    {
        $workflow = new Workflow();

        $config = new WorkflowConfig();
        $config->addNode(PendingHandler::class, [
            WorkflowResult::ON_SUCCESS => SuccessfulHandler::class,
            WorkflowResult::ON_PENDING => WaitHandler::class,
        ]);
        $config->addNode(SuccessfulHandler::class);
        $config->addNode(WaitHandler::class);

        $context = $workflow($config);

        $this->assertNotNull($context->getLatest(PendingHandler::class));
        $this->assertNotNull($context->getLatest(WaitHandler::class));
        $this->assertNull($context->getLatest(SuccessfulHandler::class));
    }

    public function testWorkflowStopsWhenNamedTransitionNotConfigured(): void
    {
        $workflow = new Workflow();

        $config = new WorkflowConfig();
        $config->addNode(RetryHandler::class, [
            WorkflowResult::ON_SUCCESS => SuccessfulHandler::class,
        ]);
        $config->addNode(SuccessfulHandler::class);

        $context = $workflow($config);

        $this->assertCount(1, $context->getChain());
        $this->assertNotNull($context->getLatest(RetryHandler::class));
    }

    public function testWorkflowWithCustomHandlerFactory(): void
    {
        $factoryCalled = false;
        $factory = function (string $class) use (&$factoryCalled): object {
            $factoryCalled = true;
            return new $class();
        };

        $workflow = new Workflow($factory);

        $config = new WorkflowConfig();
        $config->addNode(SuccessfulHandler::class);

        $workflow($config);

        $this->assertTrue($factoryCalled);
    }

    public function testRetryLoopPreservesAllInvocationsInChain(): void
    {
        $workflow = new Workflow();

        $config = new WorkflowConfig();
        // CountingRetryHandler retries twice, then succeeds — three invocations total
        $config->addNode(CountingRetryHandler::class, [
            WorkflowResult::ON_SUCCESS => SecondHandler::class,
            WorkflowResult::ON_RETRY => CountingRetryHandler::class,
        ]);
        $config->addNode(SecondHandler::class);

        $context = $workflow($config);

        $allRetryResults = $context->getAll(CountingRetryHandler::class);
        $this->assertCount(3, $allRetryResults);
        $this->assertSame(['attempt' => 1], $allRetryResults[0]->getData());
        $this->assertSame(['attempt' => 2], $allRetryResults[1]->getData());
        $this->assertSame(['attempt' => 3], $allRetryResults[2]->getData());
        $this->assertCount(4, $context->getChain());
        $this->assertNotNull($context->getLatest(SecondHandler::class));
    }
}

/**
 * Test Handlers
 */
class SuccessfulHandler
{
    public function __invoke(mixed ...$params): WorkflowResult
    {
        return new WorkflowResult(WorkflowResult::STATUS_SUCCESS, ['status' => 'success']);
    }
}

class FailingHandler
{
    public function __invoke(mixed ...$params): WorkflowResult
    {
        return new WorkflowResult(WorkflowResult::STATUS_FAIL, ['reason' => 'validation_error']);
    }
}

class ErrorHandler
{
    public function __invoke(mixed ...$params): WorkflowResult
    {
        return new WorkflowResult(WorkflowResult::STATUS_SUCCESS, ['error' => 'handled']);
    }
}

class SecondHandler
{
    public function __invoke(mixed ...$params): WorkflowResult
    {
        return new WorkflowResult(WorkflowResult::STATUS_SUCCESS, ['step' => 'second']);
    }
}

class FirstResultHandler
{
    public function __invoke(mixed ...$params): WorkflowResult
    {
        return new WorkflowResult(WorkflowResult::STATUS_SUCCESS, ['first' => 'value1']);
    }
}

class SecondResultHandler
{
    public function __invoke(mixed ...$params): WorkflowResult
    {
        return new WorkflowResult(WorkflowResult::STATUS_SUCCESS, ['second' => 'value2']);
    }
}

class ThirdResultHandler
{
    public function __invoke(mixed ...$params): WorkflowResult
    {
        return new WorkflowResult(WorkflowResult::STATUS_SUCCESS, ['third' => 'value3']);
    }
}

class ParameterEchoHandler
{
    public function __invoke(mixed ...$params): WorkflowResult
    {
        array_pop($params); // discard the trailing WorkflowContext
        return new WorkflowResult(WorkflowResult::STATUS_SUCCESS, [
            'initial' => $params,
        ]);
    }
}

class ContextSlotHandler
{
    public function __invoke(mixed ...$params): WorkflowResult
    {
        $last = end($params);
        return new WorkflowResult(WorkflowResult::STATUS_SUCCESS, [
            'contextWasLastParam' => $last instanceof WorkflowContext,
        ]);
    }
}

class NonCallableHandler
{
    // No __invoke method - not callable
}

class PreviousInspectorHandler
{
    public function __invoke(mixed ...$params): WorkflowResult
    {
        $context = end($params);
        $previous = $context->getPrevious();

        return new WorkflowResult(WorkflowResult::STATUS_SUCCESS, [
            'hadPrevious' => $previous !== null,
            'previousData' => $previous?->getData(),
        ]);
    }
}

class InvalidReturnHandler
{
    public function __invoke(mixed ...$params): array
    {
        return ['this' => 'is not a WorkflowResult'];
    }
}

class EmptySuccessHandler
{
    public function __invoke(mixed ...$params): WorkflowResult
    {
        return new WorkflowResult(WorkflowResult::STATUS_SUCCESS, []);
    }
}

class RetryHandler
{
    public function __invoke(mixed ...$params): WorkflowResult
    {
        return new WorkflowResult(WorkflowResult::ON_RETRY, ['attempts' => 1]);
    }
}

class RetryTargetHandler
{
    public function __invoke(mixed ...$params): WorkflowResult
    {
        return new WorkflowResult(WorkflowResult::STATUS_SUCCESS, ['retried' => true]);
    }
}

class PendingHandler
{
    public function __invoke(mixed ...$params): WorkflowResult
    {
        return new WorkflowResult(WorkflowResult::ON_PENDING, ['jobId' => 'abc123']);
    }
}

class WaitHandler
{
    public function __invoke(mixed ...$params): WorkflowResult
    {
        return new WorkflowResult(WorkflowResult::STATUS_SUCCESS, ['waiting' => true]);
    }
}

class CountingRetryHandler
{
    public function __invoke(mixed ...$params): WorkflowResult
    {
        $context = end($params);
        $previousAttempts = $context->getAll(self::class);
        $attempt = count($previousAttempts) + 1;

        if ($attempt < 3) {
            return new WorkflowResult(WorkflowResult::ON_RETRY, ['attempt' => $attempt]);
        }

        return new WorkflowResult(WorkflowResult::STATUS_SUCCESS, ['attempt' => $attempt]);
    }
}
