<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Tests\Unit;

use InvalidArgumentException;
use JardisSupport\Workflow\Workflow;
use JardisSupport\Workflow\WorkflowConfig;
use JardisSupport\Workflow\WorkflowResult;
use PHPUnit\Framework\TestCase;

/**
 * Unit Tests for Workflow
 *
 * Tests workflow execution with test handlers
 */
class WorkflowTest extends TestCase
{
    public function testInvokeWithEmptyConfigReturnsEmptyResult(): void
    {
        $workflow = new Workflow();
        $config = new WorkflowConfig();

        $result = $workflow($config);

        $this->assertArrayHasKey('result', $result);
        $this->assertArrayHasKey('callStack', $result);
        $this->assertSame([], $result['result']);
        $this->assertSame([], $result['callStack']);
    }

    public function testInvokeExecutesSingleNodeHandler(): void
    {
        $workflow = new Workflow();

        $config = new WorkflowConfig();
        $config->addNode(SuccessfulHandler::class);

        $result = $workflow($config);

        $this->assertArrayHasKey('SuccessfulHandler', $result['callStack']);
        $this->assertInstanceOf(WorkflowResult::class, $result['callStack']['SuccessfulHandler']);
        $this->assertSame(['status' => 'success'], $result['result']);
    }

    public function testInvokeFollowsSuccessPath(): void
    {
        $workflow = new Workflow();

        $config = new WorkflowConfig();
        $config->addNode(SuccessfulHandler::class, [
            WorkflowResult::ON_SUCCESS => SecondHandler::class,
        ]);
        $config->addNode(SecondHandler::class);

        $result = $workflow($config);

        $this->assertArrayHasKey('SuccessfulHandler', $result['callStack']);
        $this->assertArrayHasKey('SecondHandler', $result['callStack']);
        $this->assertCount(2, $result['callStack']);
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

        $result = $workflow($config);

        $this->assertArrayHasKey('FailingHandler', $result['callStack']);
        $this->assertArrayHasKey('ErrorHandler', $result['callStack']);
        $this->assertArrayNotHasKey('SuccessfulHandler', $result['callStack']);
    }

    public function testInvokeAccumulatesResults(): void
    {
        $workflow = new Workflow();

        $config = new WorkflowConfig();
        $config->addNode(FirstResultHandler::class, [
            WorkflowResult::ON_SUCCESS => SecondResultHandler::class,
        ]);
        $config->addNode(SecondResultHandler::class);

        $result = $workflow($config);

        $this->assertArrayHasKey('first', $result['result']);
        $this->assertArrayHasKey('second', $result['result']);
        $this->assertSame('value1', $result['result']['first']);
        $this->assertSame('value2', $result['result']['second']);
    }

    public function testInvokePassesParametersToHandlers(): void
    {
        $workflow = new Workflow();

        $config = new WorkflowConfig();
        $config->addNode(ParameterEchoHandler::class);

        $result = $workflow($config, 'param1', 'param2');

        $this->assertArrayHasKey('params', $result['result']);
        $this->assertContains('param1', $result['result']['params']);
        $this->assertContains('param2', $result['result']['params']);
    }

    public function testInvokeStopsWhenNoNextHandler(): void
    {
        $workflow = new Workflow();

        $config = new WorkflowConfig();
        $config->addNode(SuccessfulHandler::class);

        $result = $workflow($config);

        $this->assertCount(1, $result['callStack']);
    }

    public function testInvokeStopsWhenHandlerNotCallable(): void
    {
        $workflow = new Workflow();

        $config = new WorkflowConfig();
        $config->addNode(NonCallableHandler::class);

        $result = $workflow($config);

        $this->assertSame([], $result['callStack']);
    }

    public function testCallStackContainsShortClassName(): void
    {
        $workflow = new Workflow();

        $config = new WorkflowConfig();
        $config->addNode(SuccessfulHandler::class);

        $result = $workflow($config);

        $this->assertArrayHasKey('SuccessfulHandler', $result['callStack']);
        $this->assertArrayNotHasKey(SuccessfulHandler::class, $result['callStack']);
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

        $result = $workflow($config);

        $this->assertCount(3, $result['callStack']);
        $this->assertArrayHasKey('FirstResultHandler', $result['callStack']);
        $this->assertArrayHasKey('SecondResultHandler', $result['callStack']);
        $this->assertArrayHasKey('ThirdResultHandler', $result['callStack']);
        $this->assertArrayNotHasKey('ErrorHandler', $result['callStack']);
    }

    public function testInvokePropagatesAccumulatedResultToNextHandler(): void
    {
        $workflow = new Workflow();

        $config = new WorkflowConfig();
        $config->addNode(FirstResultHandler::class, [
            WorkflowResult::ON_SUCCESS => ResultInspectorHandler::class,
        ]);
        $config->addNode(ResultInspectorHandler::class);

        $result = $workflow($config);

        $this->assertArrayHasKey('receivedAccumulated', $result['result']);
        $this->assertTrue($result['result']['receivedAccumulated']);
    }

    public function testInvokeThrowsExceptionForNonWorkflowResultReturn(): void
    {
        $workflow = new Workflow();

        $config = new WorkflowConfig();
        $config->addNode(InvalidReturnHandler::class);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must return a WorkflowResult instance');

        $workflow($config);
    }

    public function testCallStackContainsWorkflowResultInstances(): void
    {
        $workflow = new Workflow();

        $config = new WorkflowConfig();
        $config->addNode(SuccessfulHandler::class, [
            WorkflowResult::ON_SUCCESS => SecondHandler::class,
        ]);
        $config->addNode(SecondHandler::class);

        $result = $workflow($config);

        foreach ($result['callStack'] as $nodeName => $workflowResult) {
            $this->assertInstanceOf(
                WorkflowResult::class,
                $workflowResult,
                "CallStack entry '$nodeName' should be a WorkflowResult"
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

        $result = $workflow($config);

        $this->assertTrue($result['callStack']['SuccessfulHandler']->isSuccess());
        $this->assertArrayHasKey('SecondHandler', $result['callStack']);
        $this->assertArrayNotHasKey('ErrorHandler', $result['callStack']);
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

        $result = $workflow($config);

        $this->assertTrue($result['callStack']['FailingHandler']->isFail());
        $this->assertArrayHasKey('ErrorHandler', $result['callStack']);
        $this->assertArrayNotHasKey('SuccessfulHandler', $result['callStack']);
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

        $result = $workflow($config);

        $this->assertArrayHasKey('SecondHandler', $result['callStack']);
        $this->assertArrayNotHasKey('ErrorHandler', $result['callStack']);
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

        $result = $workflow($config);

        $this->assertArrayHasKey('RetryHandler', $result['callStack']);
        $this->assertArrayHasKey('RetryTargetHandler', $result['callStack']);
        $this->assertArrayNotHasKey('SuccessfulHandler', $result['callStack']);
        $this->assertArrayNotHasKey('ErrorHandler', $result['callStack']);
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

        $result = $workflow($config);

        $this->assertArrayHasKey('PendingHandler', $result['callStack']);
        $this->assertArrayHasKey('WaitHandler', $result['callStack']);
        $this->assertArrayNotHasKey('SuccessfulHandler', $result['callStack']);
    }

    public function testWorkflowStopsWhenNamedTransitionNotConfigured(): void
    {
        $workflow = new Workflow();

        $config = new WorkflowConfig();
        $config->addNode(RetryHandler::class, [
            WorkflowResult::ON_SUCCESS => SuccessfulHandler::class,
        ]);
        $config->addNode(SuccessfulHandler::class);

        $result = $workflow($config);

        $this->assertCount(1, $result['callStack']);
        $this->assertArrayHasKey('RetryHandler', $result['callStack']);
    }

    public function testWorkflowWithTransitionsArrayApi(): void
    {
        $workflow = new Workflow();

        $config = new WorkflowConfig();
        $config->addNode(SuccessfulHandler::class, [
            WorkflowResult::ON_SUCCESS => SecondHandler::class,
            WorkflowResult::ON_FAIL => ErrorHandler::class,
        ]);
        $config->addNode(SecondHandler::class);
        $config->addNode(ErrorHandler::class);

        $result = $workflow($config);

        $this->assertArrayHasKey('SuccessfulHandler', $result['callStack']);
        $this->assertArrayHasKey('SecondHandler', $result['callStack']);
        $this->assertCount(2, $result['callStack']);
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
        return new WorkflowResult(WorkflowResult::STATUS_SUCCESS, ['params' => $params]);
    }
}

class NonCallableHandler
{
    // No __invoke method - not callable
}

class ResultInspectorHandler
{
    public function __invoke(mixed ...$params): WorkflowResult
    {
        $accumulated = end($params);

        return new WorkflowResult(WorkflowResult::STATUS_SUCCESS, [
            'receivedAccumulated' => is_array($accumulated) && isset($accumulated['first']),
            'accumulated' => $accumulated
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
