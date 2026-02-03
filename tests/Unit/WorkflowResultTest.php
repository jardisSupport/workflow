<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Tests\Unit;

use JardisSupport\Workflow\WorkflowResult;
use PHPUnit\Framework\TestCase;

/**
 * Unit Tests for WorkflowResult
 *
 * Tests the WorkflowResult value object for workflow transition control
 */
class WorkflowResultTest extends TestCase
{
    public function testSuccessCreatesSuccessfulResult(): void
    {
        $result = new WorkflowResult(WorkflowResult::STATUS_SUCCESS);

        $this->assertTrue($result->isSuccess());
        $this->assertFalse($result->isFail());
        $this->assertSame(WorkflowResult::STATUS_SUCCESS, $result->getStatus());
    }

    public function testSuccessWithDataStoresData(): void
    {
        $data = ['orderId' => 123, 'status' => 'created'];

        $result = new WorkflowResult(WorkflowResult::STATUS_SUCCESS, $data);

        $this->assertSame($data, $result->getData());
    }

    public function testFailCreatesFailedResult(): void
    {
        $result = new WorkflowResult(WorkflowResult::STATUS_FAIL);

        $this->assertTrue($result->isFail());
        $this->assertFalse($result->isSuccess());
        $this->assertSame(WorkflowResult::STATUS_FAIL, $result->getStatus());
    }

    public function testFailWithDataStoresData(): void
    {
        $data = ['error' => 'Validation failed', 'fields' => ['email']];

        $result = new WorkflowResult(WorkflowResult::STATUS_FAIL, $data);

        $this->assertSame($data, $result->getData());
    }

    public function testTransitionConstantCreatesNamedTransition(): void
    {
        $result = new WorkflowResult(WorkflowResult::ON_PENDING);

        $this->assertTrue($result->hasExplicitTransition());
        $this->assertSame(WorkflowResult::ON_PENDING, $result->getTransition());
        $this->assertSame(WorkflowResult::ON_PENDING, $result->getStatus());
    }

    public function testTransitionWithDataStoresData(): void
    {
        $data = ['retryCount' => 3];

        $result = new WorkflowResult(WorkflowResult::ON_RETRY, $data);

        $this->assertSame($data, $result->getData());
        $this->assertSame(WorkflowResult::ON_RETRY, $result->getTransition());
    }

    public function testSuccessHasNoExplicitTransition(): void
    {
        $result = new WorkflowResult(WorkflowResult::STATUS_SUCCESS);

        $this->assertFalse($result->hasExplicitTransition());
        $this->assertNull($result->getTransition());
    }

    public function testFailHasNoExplicitTransition(): void
    {
        $result = new WorkflowResult(WorkflowResult::STATUS_FAIL);

        $this->assertFalse($result->hasExplicitTransition());
        $this->assertNull($result->getTransition());
    }

    public function testDataCanBeNull(): void
    {
        $result = new WorkflowResult(WorkflowResult::STATUS_SUCCESS, null);

        $this->assertNull($result->getData());
    }

    public function testDataCanBeScalar(): void
    {
        $result = new WorkflowResult(WorkflowResult::STATUS_SUCCESS, 42);

        $this->assertSame(42, $result->getData());
    }

    public function testDataCanBeObject(): void
    {
        $object = new \stdClass();
        $object->id = 1;

        $result = new WorkflowResult(WorkflowResult::STATUS_SUCCESS, $object);

        $this->assertSame($object, $result->getData());
    }

    public function testCustomTransitionIsNeitherSuccessNorFail(): void
    {
        $result = new WorkflowResult(WorkflowResult::ON_PENDING);

        $this->assertFalse($result->isSuccess());
        $this->assertFalse($result->isFail());
    }

    public function testStatusConstantsAreCorrect(): void
    {
        $this->assertSame('success', WorkflowResult::STATUS_SUCCESS);
        $this->assertSame('fail', WorkflowResult::STATUS_FAIL);
    }

    public function testTransitionConstantsAreCorrect(): void
    {
        $this->assertSame('onSuccess', WorkflowResult::ON_SUCCESS);
        $this->assertSame('onFail', WorkflowResult::ON_FAIL);
        $this->assertSame('onError', WorkflowResult::ON_ERROR);
        $this->assertSame('onTimeout', WorkflowResult::ON_TIMEOUT);
        $this->assertSame('onRetry', WorkflowResult::ON_RETRY);
        $this->assertSame('onSkip', WorkflowResult::ON_SKIP);
        $this->assertSame('onPending', WorkflowResult::ON_PENDING);
        $this->assertSame('onCancel', WorkflowResult::ON_CANCEL);
    }

    public function testAllTransitionConstantsCreateExplicitTransitions(): void
    {
        $transitions = [
            WorkflowResult::ON_SUCCESS,
            WorkflowResult::ON_FAIL,
            WorkflowResult::ON_ERROR,
            WorkflowResult::ON_TIMEOUT,
            WorkflowResult::ON_RETRY,
            WorkflowResult::ON_SKIP,
            WorkflowResult::ON_PENDING,
            WorkflowResult::ON_CANCEL,
        ];

        foreach ($transitions as $transition) {
            $result = new WorkflowResult($transition);
            $this->assertTrue(
                $result->hasExplicitTransition(),
                "Transition '$transition' should be explicit"
            );
            $this->assertSame($transition, $result->getTransition());
        }
    }

    public function testOnSuccessTransitionIsExplicitNotStatusBased(): void
    {
        $explicitResult = new WorkflowResult(WorkflowResult::ON_SUCCESS);
        $statusResult = new WorkflowResult(WorkflowResult::STATUS_SUCCESS);

        $this->assertTrue($explicitResult->hasExplicitTransition());
        $this->assertFalse($statusResult->hasExplicitTransition());

        $this->assertFalse($explicitResult->isSuccess());
        $this->assertTrue($statusResult->isSuccess());
    }
}
