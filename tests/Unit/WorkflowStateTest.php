<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Tests\Unit;

use JardisSupport\Contract\Workflow\AggregateResponse;
use JardisSupport\Contract\Workflow\WorkflowContextInterface;
use JardisSupport\Workflow\Workflow;
use JardisSupport\Workflow\WorkflowConfig;
use JardisSupport\Workflow\WorkflowResult;
use JardisSupport\Workflow\WorkflowState;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Unit Tests for WorkflowState
 *
 * Verifies the typed payload/original/modified slots, ordered Fan-in/Fan-out
 * accumulation, delegation of the full WorkflowContextInterface (chain + mantle
 * slots) to the internal companion, and that a WorkflowState is accepted by the
 * engine as an execution context and returned type-compatibly.
 */
class WorkflowStateTest extends TestCase
{
    public function testStateIsAWorkflowContext(): void
    {
        $state = new WorkflowState();

        $this->assertInstanceOf(WorkflowContextInterface::class, $state);
    }

    public function testPayloadIsAssignableAndReadable(): void
    {
        $payload = (object) ['orderId' => 42];

        $state = new WorkflowState();
        $state->payload = $payload;

        $this->assertSame($payload, $state->payload);
    }

    public function testAddOriginalPreservesOrderAndType(): void
    {
        $first = new FakeCounterResponse();
        $second = new FakeMeterResponse();

        $state = new WorkflowState();
        $state->addOriginal($first);
        $state->addOriginal($second);

        // Ordered list, mixed aggregate types, order preserved.
        $this->assertSame([$first, $second], $state->original);
        $this->assertInstanceOf(AggregateResponse::class, $state->original[0]);
        $this->assertInstanceOf(AggregateResponse::class, $state->original[1]);
    }

    public function testAddModifiedPreservesOrder(): void
    {
        $a = (object) ['cmd' => 'a'];
        $b = (object) ['cmd' => 'b'];

        $state = new WorkflowState();
        $state->addModified($a);
        $state->addModified($b);

        $this->assertSame([$a, $b], $state->modified);
    }

    public function testDelegatesChainToCompanion(): void
    {
        $state = new WorkflowState();
        $result = new WorkflowResult(WorkflowResult::ON_SUCCESS, ['k' => 'v']);

        $state->append(StateAwareHandler::class, $result);

        $this->assertCount(1, $state->getChain());
        $this->assertNotNull($state->getLatest(StateAwareHandler::class));
        $this->assertSame(['k' => 'v'], $state->getPrevious()?->getData());
        $this->assertCount(1, $state->getAll(StateAwareHandler::class));
    }

    public function testDelegatesMantleSlots(): void
    {
        $state = new WorkflowState();
        $exception = new RuntimeException('boom');

        $state->setReference('ref-value');
        $state->setResponse('resp-value');
        $state->setException($exception);

        $this->assertSame('ref-value', $state->reference());
        $this->assertSame('resp-value', $state->response());
        $this->assertSame($exception, $state->getException());
    }

    public function testWorkflowStateActsAsEngineContext(): void
    {
        $workflow = new Workflow();

        $config = new WorkflowConfig();
        $config->addNode(StateAwareHandler::class, [
            WorkflowResult::ON_SUCCESS => SecondStateAwareHandler::class,
        ]);
        $config->addNode(SecondStateAwareHandler::class);

        $state = new WorkflowState();
        $state->payload = (object) ['orderId' => 7];
        $state->addOriginal(new FakeCounterResponse());

        $returned = $workflow($config, null, $state);

        // The engine used and returned the very same state, with the run appended.
        $this->assertSame($state, $returned);
        $this->assertCount(2, $state->getChain());
        $this->assertNotNull($state->getLatest(StateAwareHandler::class));
        $this->assertNotNull($state->getLatest(SecondStateAwareHandler::class));
        // Typed slots are untouched by the engine.
        $this->assertCount(1, $state->original);
        $this->assertSame(7, $state->payload->orderId);
    }
}

final class FakeCounterResponse implements AggregateResponse
{
}

final class FakeMeterResponse implements AggregateResponse
{
}

/**
 * Handlers in a process run type-hint the interface, not the concrete WorkflowContext,
 * so a WorkflowState can be passed through as the execution context.
 */
class StateAwareHandler
{
    public function __invoke(WorkflowContextInterface $context): WorkflowResult
    {
        return new WorkflowResult(WorkflowResult::ON_SUCCESS, ['ran' => 'first']);
    }
}

class SecondStateAwareHandler
{
    public function __invoke(WorkflowContextInterface $context): WorkflowResult
    {
        return new WorkflowResult(WorkflowResult::ON_SUCCESS, ['ran' => 'second']);
    }
}
