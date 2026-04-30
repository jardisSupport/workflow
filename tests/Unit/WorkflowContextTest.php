<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Tests\Unit;

use JardisSupport\Workflow\WorkflowContext;
use JardisSupport\Workflow\WorkflowResult;
use PHPUnit\Framework\TestCase;

/**
 * Unit Tests for WorkflowContext
 *
 * Verifies append/getPrevious/getLatest/getAll/getChain semantics independently
 * of the workflow executor — including the no-overwrite guarantee for
 * re-invocations of the same handler.
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
        $result = new WorkflowResult(WorkflowResult::STATUS_SUCCESS, ['k' => 'v']);

        $context->append('App\\Handler\\First', $result);

        $this->assertSame($result, $context->getLatest('App\\Handler\\First'));
        $this->assertSame([$result], $context->getAll('App\\Handler\\First'));
    }

    public function testAppendUpdatesPreviousToTheNewlyAppendedResult(): void
    {
        $context = new WorkflowContext();
        $first = new WorkflowResult(WorkflowResult::STATUS_SUCCESS, ['n' => 1]);
        $second = new WorkflowResult(WorkflowResult::STATUS_SUCCESS, ['n' => 2]);

        $context->append('App\\Handler\\First', $first);
        $this->assertSame($first, $context->getPrevious());

        $context->append('App\\Handler\\Second', $second);
        $this->assertSame($second, $context->getPrevious());
    }

    public function testChainPreservesInsertionOrder(): void
    {
        $context = new WorkflowContext();
        $context->append('App\\Handler\\A', new WorkflowResult(WorkflowResult::STATUS_SUCCESS));
        $context->append('App\\Handler\\B', new WorkflowResult(WorkflowResult::STATUS_SUCCESS));
        $context->append('App\\Handler\\C', new WorkflowResult(WorkflowResult::STATUS_SUCCESS));

        $handlers = array_map(fn(array $entry): string => $entry['handler'], $context->getChain());

        $this->assertSame(
            ['App\\Handler\\A', 'App\\Handler\\B', 'App\\Handler\\C'],
            $handlers
        );
    }

    public function testReinvocationDoesNotOverwriteEarlierEntry(): void
    {
        $context = new WorkflowContext();
        $first = new WorkflowResult(WorkflowResult::STATUS_SUCCESS, ['attempt' => 1]);
        $other = new WorkflowResult(WorkflowResult::STATUS_SUCCESS, ['x' => 'y']);
        $second = new WorkflowResult(WorkflowResult::STATUS_SUCCESS, ['attempt' => 2]);

        $context->append('App\\Handler\\Retry', $first);
        $context->append('App\\Handler\\Other', $other);
        $context->append('App\\Handler\\Retry', $second);

        $this->assertCount(3, $context->getChain());
        $this->assertSame([$first, $second], $context->getAll('App\\Handler\\Retry'));
        $this->assertSame($second, $context->getLatest('App\\Handler\\Retry'));
        $this->assertSame($second, $context->getPrevious());
    }

    public function testGetLatestReturnsMostRecentInvocation(): void
    {
        $context = new WorkflowContext();
        $r1 = new WorkflowResult(WorkflowResult::STATUS_SUCCESS, ['v' => 1]);
        $r2 = new WorkflowResult(WorkflowResult::STATUS_SUCCESS, ['v' => 2]);
        $r3 = new WorkflowResult(WorkflowResult::STATUS_SUCCESS, ['v' => 3]);

        $context->append('App\\Handler\\Validate', $r1);
        $context->append('App\\Handler\\Other', new WorkflowResult(WorkflowResult::STATUS_SUCCESS));
        $context->append('App\\Handler\\Validate', $r2);
        $context->append('App\\Handler\\Other', new WorkflowResult(WorkflowResult::STATUS_SUCCESS));
        $context->append('App\\Handler\\Validate', $r3);

        $this->assertSame($r3, $context->getLatest('App\\Handler\\Validate'));
        $this->assertSame([$r1, $r2, $r3], $context->getAll('App\\Handler\\Validate'));
    }

    public function testGetChainEntriesPairHandlerAndResult(): void
    {
        $context = new WorkflowContext();
        $a = new WorkflowResult(WorkflowResult::STATUS_SUCCESS, ['a' => 1]);
        $b = new WorkflowResult(WorkflowResult::STATUS_FAIL, ['b' => 2]);

        $context->append('App\\Handler\\A', $a);
        $context->append('App\\Handler\\B', $b);

        $chain = $context->getChain();
        $this->assertSame('App\\Handler\\A', $chain[0]['handler']);
        $this->assertSame($a, $chain[0]['result']);
        $this->assertSame('App\\Handler\\B', $chain[1]['handler']);
        $this->assertSame($b, $chain[1]['result']);
    }
}
