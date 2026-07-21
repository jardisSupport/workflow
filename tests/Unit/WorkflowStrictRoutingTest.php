<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Tests\Unit;

use JardisSupport\Workflow\Exception\UnroutedStatusException;
use JardisSupport\Workflow\Workflow;
use JardisSupport\Workflow\WorkflowConfig;
use JardisSupport\Workflow\WorkflowContext;
use JardisSupport\Workflow\WorkflowResult;
use PHPUnit\Framework\TestCase;

/**
 * Unit Tests for the opt-in `strictRouting` flag on WorkflowConfig.
 *
 * Covers the two acceptance criteria of the A3/R4b design (Format-Härtung
 * PLAN.md, Gremium-Befund G1):
 *  - strict + a status with no transition key at all -> UnroutedStatusException
 *  - strict + a status explicitly mapped to null (a declared terminal end) ->
 *    the run ends regularly, no exception
 * plus characterization tests that pin down the two pre-existing, non-strict
 * behaviours the design explicitly must not break: the hand-off feature (a
 * mapped target that is not itself a registered node, R5 routing-safety) and
 * partial mapping (a status with no transitions entry at all silently ends
 * the run). Both remain byte-identical to v1.1.0 when strictRouting is
 * omitted or false, and the hand-off case remains legal even in strict mode.
 */
class WorkflowStrictRoutingTest extends TestCase
{
    public function testStrictRoutingThrowsUnroutedStatusExceptionForMissingTransitionKey(): void
    {
        $workflow = new Workflow();

        $config = new WorkflowConfig(strictRouting: true);
        $config->addNode(StrictOnlySuccessMappedHandler::class, [
            WorkflowResult::ON_SUCCESS => StrictTargetHandler::class,
        ]);
        $config->addNode(StrictTargetHandler::class);

        $this->expectException(UnroutedStatusException::class);

        // Handler emits ON_FAIL, but the node's transitions map has no 'onFail' key at all.
        $workflow($config);
    }

    public function testUnroutedStatusExceptionExposesNodeAndStatus(): void
    {
        $workflow = new Workflow();

        $config = new WorkflowConfig(strictRouting: true);
        $config->addNode(StrictOnlySuccessMappedHandler::class, [
            WorkflowResult::ON_SUCCESS => StrictTargetHandler::class,
        ]);
        $config->addNode(StrictTargetHandler::class);

        try {
            $workflow($config);
            $this->fail('Expected UnroutedStatusException was not thrown');
        } catch (UnroutedStatusException $e) {
            $this->assertSame(StrictOnlySuccessMappedHandler::class, $e->getNode());
            $this->assertSame(WorkflowResult::ON_FAIL, $e->getStatus());
            $this->assertStringContainsString(StrictOnlySuccessMappedHandler::class, $e->getMessage());
            $this->assertStringContainsString(WorkflowResult::ON_FAIL, $e->getMessage());
        }
    }

    public function testStrictRoutingAllowsExplicitNullAsDeclaredTerminalEnd(): void
    {
        $workflow = new Workflow();

        $config = new WorkflowConfig(strictRouting: true);
        $config->addNode(StrictOnlySuccessMappedHandler::class, [
            WorkflowResult::ON_SUCCESS => StrictTargetHandler::class,
            WorkflowResult::ON_FAIL => null,
        ]);
        $config->addNode(StrictTargetHandler::class);

        $context = $workflow($config);

        // The onFail key exists (mapped to null) -> declared terminal, no exception, regular end.
        $this->assertCount(1, $context->getChain());
        $this->assertNotNull($context->getLatest(StrictOnlySuccessMappedHandler::class));
        $this->assertSame(
            WorkflowResult::ON_FAIL,
            $context->getLatest(StrictOnlySuccessMappedHandler::class)?->getStatus()
        );
    }

    public function testStrictRoutingStillAllowsHandOffToUnregisteredNode(): void
    {
        $workflow = new Workflow();

        // onEvent is mapped, but its target is never addNode()'d -> R5 hand-off, legal even in strict mode.
        $config = new WorkflowConfig(strictRouting: true);
        $config->addNode(StrictEventHandler::class, [
            WorkflowResult::ON_EVENT => StrictTargetHandler::class,
        ]);

        $context = $workflow($config);

        $this->assertCount(1, $context->getChain());
        $this->assertNotNull($context->getLatest(StrictEventHandler::class));
    }

    public function testNonStrictRoutingPreservesHandOffBehaviourCharacterization(): void
    {
        // Characterization: default (strictRouting omitted) config, mapped target not registered.
        $workflow = new Workflow();

        $config = new WorkflowConfig();
        $config->addNode(StrictEventHandler::class, [
            WorkflowResult::ON_EVENT => StrictTargetHandler::class,
        ]);

        $context = $workflow($config);

        $this->assertCount(1, $context->getChain());
        $this->assertNotNull($context->getLatest(StrictEventHandler::class));
        $this->assertNull($context->getLatest(StrictTargetHandler::class));
    }

    public function testNonStrictRoutingPreservesPartialMappingBehaviourCharacterization(): void
    {
        // Characterization: default (strictRouting omitted) config, status has no transitions
        // entry at all (real-world precedent: RuleGuardedOrderIntakeHandler maps only ON_FAIL).
        $workflow = new Workflow();

        $config = new WorkflowConfig();
        $config->addNode(StrictOnlySuccessMappedHandler::class, [
            WorkflowResult::ON_SUCCESS => StrictTargetHandler::class,
        ]);
        $config->addNode(StrictTargetHandler::class);

        // Handler emits ON_FAIL, which has no key at all in the transitions map.
        $context = $workflow($config);

        $this->assertCount(1, $context->getChain());
        $this->assertNotNull($context->getLatest(StrictOnlySuccessMappedHandler::class));
        $this->assertNull($context->getLatest(StrictTargetHandler::class));
    }

    public function testNonStrictRoutingExplicitlyFalseBehavesIdenticallyToOmittedFlag(): void
    {
        $workflow = new Workflow();

        $config = new WorkflowConfig(strictRouting: false);
        $config->addNode(StrictOnlySuccessMappedHandler::class, [
            WorkflowResult::ON_SUCCESS => StrictTargetHandler::class,
        ]);
        $config->addNode(StrictTargetHandler::class);

        $context = $workflow($config);

        $this->assertCount(1, $context->getChain());
        $this->assertNull($context->getLatest(StrictTargetHandler::class));
    }
}

/**
 * Test handlers for strict-routing scenarios — all share the single-arg
 * signature __invoke(WorkflowContext).
 */
class StrictOnlySuccessMappedHandler
{
    public function __invoke(WorkflowContext $context): WorkflowResult
    {
        return new WorkflowResult(WorkflowResult::ON_FAIL, ['reason' => 'validation_error']);
    }
}

class StrictTargetHandler
{
    public function __invoke(WorkflowContext $context): WorkflowResult
    {
        return new WorkflowResult(WorkflowResult::ON_SUCCESS, ['reached' => true]);
    }
}

class StrictEventHandler
{
    public function __invoke(WorkflowContext $context): WorkflowResult
    {
        return new WorkflowResult(WorkflowResult::ON_EVENT, ['eventName' => 'HandOff']);
    }
}
