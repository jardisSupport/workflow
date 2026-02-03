<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Tests\Unit;

use InvalidArgumentException;
use JardisSupport\Workflow\WorkflowConfig;
use JardisSupport\Workflow\WorkflowResult;
use PHPUnit\Framework\TestCase;

/**
 * Unit Tests for WorkflowConfig
 *
 * Tests workflow configuration building and node management
 */
class WorkflowConfigTest extends TestCase
{
    public function testGetNodesReturnsEmptyArrayWhenNoNodesAdded(): void
    {
        $config = new WorkflowConfig();

        $this->assertSame([], $config->getNodes());
    }

    public function testAddNodeStoresNodeWithCorrectStructure(): void
    {
        $config = new WorkflowConfig();
        $handlerClass = ConfigTestHandler::class;

        $config->addNode($handlerClass, [
            WorkflowResult::ON_SUCCESS => 'SuccessHandler',
            WorkflowResult::ON_FAIL => 'FailHandler',
        ]);

        $nodes = $config->getNodes();

        $this->assertCount(1, $nodes);
        $this->assertArrayHasKey(0, $nodes);
        $this->assertSame($handlerClass, $nodes[0]['handler']);
        $this->assertArrayHasKey('transitions', $nodes[0]);
        $this->assertSame('SuccessHandler', $nodes[0]['transitions'][WorkflowResult::ON_SUCCESS]);
        $this->assertSame('FailHandler', $nodes[0]['transitions'][WorkflowResult::ON_FAIL]);
    }

    public function testAddNodeWithEmptyTransitions(): void
    {
        $config = new WorkflowConfig();
        $handlerClass = ConfigTestHandler::class;

        $config->addNode($handlerClass);

        $nodes = $config->getNodes();

        $this->assertSame([], $nodes[0]['transitions']);
    }

    public function testAddNodeReturnsFluentInterface(): void
    {
        $config = new WorkflowConfig();
        $handlerClass = ConfigTestHandler::class;

        $result = $config->addNode($handlerClass);

        $this->assertSame($config, $result);
    }

    public function testAddNodeAllowsMethodChaining(): void
    {
        $config = new WorkflowConfig();

        $result = $config
            ->addNode(ConfigTestHandler::class, [
                WorkflowResult::ON_SUCCESS => ConfigTestSuccessHandler::class,
            ])
            ->addNode(ConfigTestSuccessHandler::class);

        $this->assertSame($config, $result);
        $this->assertCount(2, $config->getNodes());
    }

    public function testAddNodeThrowsExceptionForNonExistentClass(): void
    {
        $config = new WorkflowConfig();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('does not exist');

        $config->addNode('NonExistent\\Class\\Name');
    }

    public function testAddNodePreventsAddingDuplicateHandler(): void
    {
        $config = new WorkflowConfig();
        $handlerClass = ConfigTestHandler::class;

        $config->addNode($handlerClass, [
            WorkflowResult::ON_SUCCESS => 'First',
        ]);
        $config->addNode($handlerClass, [
            WorkflowResult::ON_SUCCESS => 'Second',
        ]);

        $nodes = $config->getNodes();

        $this->assertCount(1, $nodes);
        $this->assertSame('First', $nodes[0]['transitions'][WorkflowResult::ON_SUCCESS]);
    }

    public function testNodesAreIndexedSequentially(): void
    {
        $config = new WorkflowConfig();

        $config->addNode(ConfigTestHandler::class);
        $config->addNode(ConfigTestSuccessHandler::class);
        $config->addNode(ConfigTestFailHandler::class);

        $nodes = $config->getNodes();

        $this->assertArrayHasKey(0, $nodes);
        $this->assertArrayHasKey(1, $nodes);
        $this->assertArrayHasKey(2, $nodes);
        $this->assertSame(ConfigTestHandler::class, $nodes[0]['handler']);
        $this->assertSame(ConfigTestSuccessHandler::class, $nodes[1]['handler']);
        $this->assertSame(ConfigTestFailHandler::class, $nodes[2]['handler']);
    }

    public function testAddNodeWithTransitionsArray(): void
    {
        $config = new WorkflowConfig();

        $config->addNode(ConfigTestHandler::class, [
            WorkflowResult::ON_SUCCESS => ConfigTestSuccessHandler::class,
            WorkflowResult::ON_FAIL => ConfigTestFailHandler::class,
        ]);

        $transitions = $config->getTransitions(ConfigTestHandler::class);

        $this->assertNotNull($transitions);
        $this->assertSame(ConfigTestSuccessHandler::class, $transitions[WorkflowResult::ON_SUCCESS]);
        $this->assertSame(ConfigTestFailHandler::class, $transitions[WorkflowResult::ON_FAIL]);
    }

    public function testAddNodeWithCustomTransitions(): void
    {
        $config = new WorkflowConfig();

        $config->addNode(ConfigTestHandler::class, [
            WorkflowResult::ON_SUCCESS => ConfigTestSuccessHandler::class,
            WorkflowResult::ON_FAIL => ConfigTestFailHandler::class,
            WorkflowResult::ON_RETRY => ConfigTestHandler::class,
            WorkflowResult::ON_PENDING => ConfigTestSuccessHandler::class,
        ]);

        $transitions = $config->getTransitions(ConfigTestHandler::class);

        $this->assertNotNull($transitions);
        $this->assertSame(ConfigTestSuccessHandler::class, $transitions[WorkflowResult::ON_SUCCESS]);
        $this->assertSame(ConfigTestFailHandler::class, $transitions[WorkflowResult::ON_FAIL]);
        $this->assertSame(ConfigTestHandler::class, $transitions[WorkflowResult::ON_RETRY]);
        $this->assertSame(ConfigTestSuccessHandler::class, $transitions[WorkflowResult::ON_PENDING]);
    }

    public function testGetTransitionsReturnsNullForUnknownHandler(): void
    {
        $config = new WorkflowConfig();

        $transitions = $config->getTransitions('Unknown\\Handler');

        $this->assertNull($transitions);
    }

    public function testGetNodesReturnsFullTransitionData(): void
    {
        $config = new WorkflowConfig();

        $config->addNode(ConfigTestHandler::class, [
            WorkflowResult::ON_SUCCESS => ConfigTestSuccessHandler::class,
            WorkflowResult::ON_RETRY => ConfigTestHandler::class,
        ]);

        $nodes = $config->getNodes();

        $this->assertCount(1, $nodes);
        $this->assertArrayHasKey('transitions', $nodes[0]);
        $this->assertSame(ConfigTestSuccessHandler::class, $nodes[0]['transitions'][WorkflowResult::ON_SUCCESS]);
        $this->assertSame(ConfigTestHandler::class, $nodes[0]['transitions'][WorkflowResult::ON_RETRY]);
    }
}

/**
 * Test handler classes for WorkflowConfig tests
 */
class ConfigTestHandler
{
    public function __invoke(): void
    {
    }
}

class ConfigTestSuccessHandler
{
    public function __invoke(): void
    {
    }
}

class ConfigTestFailHandler
{
    public function __invoke(): void
    {
    }
}
