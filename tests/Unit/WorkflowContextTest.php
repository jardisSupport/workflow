<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Tests\Unit;

use JardisSupport\Contract\Workflow\WorkflowResultInterface;
use JardisSupport\Workflow\WorkflowContext;
use JardisSupport\Workflow\WorkflowResult;
use PHPUnit\Framework\TestCase;

/**
 * Unit Tests for WorkflowContext
 *
 * Verifies append/getPrevious/getLatest/getAll/getChain semantics on the
 * flat handler-stamped chain, plus the three mantle slots (reference, response,
 * exception).
 */
class WorkflowContextTest extends TestCase
{
    public function testEmptyContextHasNoPreviousAndEmptyChain(): void
    {
        $context = new WorkflowContext();

        $this->assertNull($context->getPrevious());
        $this->assertSame([], $context->getChain());
    }

    public function testGetLatestReturnsNullForUnknownHandler(): void
    {
        $context = new WorkflowContext();

        $this->assertNull($context->getLatest('App\\NotInChain\\Handler'));
    }

    public function testGetAllReturnsEmptyListForUnknownHandler(): void
    {
        $context = new WorkflowContext();

        $this->assertSame([], $context->getAll('App\\NotInChain\\Handler'));
    }

    public function testAppendStoresEntryAndExposesAsLatest(): void
    {
        $context = new WorkflowContext();
        $result = new WorkflowResult(WorkflowResult::ON_SUCCESS, ['k' => 'v']);

        $context->append('App\\Handler\\First', $result);

        $latest = $context->getLatest('App\\Handler\\First');
        $this->assertNotNull($latest);
        $this->assertSame(['k' => 'v'], $latest->getData());
        $this->assertSame('App\\Handler\\First', $latest->getHandlerFqcn());
    }

    public function testAppendStampsTheResultWithTheHandlerFqcn(): void
    {
        $context = new WorkflowContext();
        $result = new WorkflowResult(WorkflowResult::ON_SUCCESS);

        $this->assertNull($result->getHandlerFqcn());

        $context->append('App\\Handler\\Stamp', $result);

        $chain = $context->getChain();
        $this->assertCount(1, $chain);
        $this->assertSame('App\\Handler\\Stamp', $chain[0]->getHandlerFqcn());
        // Source result remains unstamped — append clones via withHandler().
        $this->assertNull($result->getHandlerFqcn());
    }

    public function testAppendUpdatesPreviousToTheNewlyAppendedResult(): void
    {
        $context = new WorkflowContext();
        $first = new WorkflowResult(WorkflowResult::ON_SUCCESS, ['n' => 1]);
        $second = new WorkflowResult(WorkflowResult::ON_SUCCESS, ['n' => 2]);

        $context->append('App\\Handler\\First', $first);
        $previous = $context->getPrevious();
        $this->assertNotNull($previous);
        $this->assertSame(['n' => 1], $previous->getData());

        $context->append('App\\Handler\\Second', $second);
        $previous = $context->getPrevious();
        $this->assertNotNull($previous);
        $this->assertSame(['n' => 2], $previous->getData());
    }

    public function testChainPreservesInsertionOrder(): void
    {
        $context = new WorkflowContext();
        $context->append('App\\Handler\\A', new WorkflowResult(WorkflowResult::ON_SUCCESS));
        $context->append('App\\Handler\\B', new WorkflowResult(WorkflowResult::ON_SUCCESS));
        $context->append('App\\Handler\\C', new WorkflowResult(WorkflowResult::ON_SUCCESS));

        $handlers = array_map(
            fn(WorkflowResultInterface $r): ?string => $r->getHandlerFqcn(),
            $context->getChain(),
        );

        $this->assertSame(
            ['App\\Handler\\A', 'App\\Handler\\B', 'App\\Handler\\C'],
            $handlers,
        );
    }

    public function testReinvocationDoesNotOverwriteEarlierEntry(): void
    {
        $context = new WorkflowContext();
        $first = new WorkflowResult(WorkflowResult::ON_SUCCESS, ['attempt' => 1]);
        $other = new WorkflowResult(WorkflowResult::ON_SUCCESS, ['x' => 'y']);
        $second = new WorkflowResult(WorkflowResult::ON_SUCCESS, ['attempt' => 2]);

        $context->append('App\\Handler\\Retry', $first);
        $context->append('App\\Handler\\Other', $other);
        $context->append('App\\Handler\\Retry', $second);

        $this->assertCount(3, $context->getChain());

        $retryResults = $context->getAll('App\\Handler\\Retry');
        $this->assertCount(2, $retryResults);
        $this->assertSame(['attempt' => 1], $retryResults[0]->getData());
        $this->assertSame(['attempt' => 2], $retryResults[1]->getData());

        $latestRetry = $context->getLatest('App\\Handler\\Retry');
        $this->assertNotNull($latestRetry);
        $this->assertSame(['attempt' => 2], $latestRetry->getData());

        $previous = $context->getPrevious();
        $this->assertNotNull($previous);
        $this->assertSame(['attempt' => 2], $previous->getData());
    }

    public function testGetLatestReturnsMostRecentInvocation(): void
    {
        $context = new WorkflowContext();
        $r1 = new WorkflowResult(WorkflowResult::ON_SUCCESS, ['v' => 1]);
        $r2 = new WorkflowResult(WorkflowResult::ON_SUCCESS, ['v' => 2]);
        $r3 = new WorkflowResult(WorkflowResult::ON_SUCCESS, ['v' => 3]);

        $context->append('App\\Handler\\Validate', $r1);
        $context->append('App\\Handler\\Other', new WorkflowResult(WorkflowResult::ON_SUCCESS));
        $context->append('App\\Handler\\Validate', $r2);
        $context->append('App\\Handler\\Other', new WorkflowResult(WorkflowResult::ON_SUCCESS));
        $context->append('App\\Handler\\Validate', $r3);

        $latest = $context->getLatest('App\\Handler\\Validate');
        $this->assertNotNull($latest);
        $this->assertSame(['v' => 3], $latest->getData());

        $allValidate = $context->getAll('App\\Handler\\Validate');
        $this->assertCount(3, $allValidate);
        $this->assertSame(['v' => 1], $allValidate[0]->getData());
        $this->assertSame(['v' => 2], $allValidate[1]->getData());
        $this->assertSame(['v' => 3], $allValidate[2]->getData());
    }

    public function testGetChainReturnsFlatStampedResults(): void
    {
        $context = new WorkflowContext();
        $a = new WorkflowResult(WorkflowResult::ON_SUCCESS, ['a' => 1]);
        $b = new WorkflowResult(WorkflowResult::ON_FAIL, ['b' => 2]);

        $context->append('App\\Handler\\A', $a);
        $context->append('App\\Handler\\B', $b);

        $chain = $context->getChain();
        $this->assertCount(2, $chain);
        $this->assertInstanceOf(WorkflowResultInterface::class, $chain[0]);
        $this->assertSame('App\\Handler\\A', $chain[0]->getHandlerFqcn());
        $this->assertSame(['a' => 1], $chain[0]->getData());
        $this->assertSame('App\\Handler\\B', $chain[1]->getHandlerFqcn());
        $this->assertSame(['b' => 2], $chain[1]->getData());
    }

    public function testReferenceSlotIsNullByDefault(): void
    {
        $context = new WorkflowContext();

        $this->assertNull($context->reference());
    }

    public function testReferenceSlotRoundTrip(): void
    {
        $context = new WorkflowContext();
        $value = ['snapshot' => 'counter-2025-01'];

        $context->setReference($value);

        $this->assertSame($value, $context->reference());
    }

    public function testReferenceSlotIsLastWriteWins(): void
    {
        $context = new WorkflowContext();
        $context->setReference('first');
        $context->setReference('second');

        $this->assertSame('second', $context->reference());
    }

    public function testResponseSlotIsNullByDefault(): void
    {
        $context = new WorkflowContext();

        $this->assertNull($context->response());
    }

    public function testResponseSlotRoundTrip(): void
    {
        $context = new WorkflowContext();
        $payload = ['ok' => true, 'changedIds' => [1, 2, 3]];

        $context->setResponse($payload);

        $this->assertSame($payload, $context->response());
    }

    public function testExceptionSlotIsNullByDefault(): void
    {
        $context = new WorkflowContext();

        $this->assertNull($context->getException());
    }

    public function testExceptionSlotRoundTrip(): void
    {
        $context = new WorkflowContext();
        $e = new \RuntimeException('boom');

        $context->setException($e);

        $this->assertSame($e, $context->getException());
    }

    public function testSlotsAreIndependentOfChain(): void
    {
        $context = new WorkflowContext();
        $context->setReference(['ref' => 1]);
        $context->setResponse(['resp' => 2]);
        $context->setException(new \RuntimeException('x'));

        $this->assertSame([], $context->getChain());
        $this->assertNull($context->getPrevious());
        $this->assertSame(['ref' => 1], $context->reference());
        $this->assertSame(['resp' => 2], $context->response());
        $this->assertNotNull($context->getException());
    }
}
