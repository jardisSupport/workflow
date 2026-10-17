<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Tests\Unit\Handler\Node;

use JardisSupport\Workflow\Data\NodeResult;
use JardisSupport\Workflow\Data\ProcessContext;
use JardisSupport\Workflow\Exception\ConstructionException;
use JardisSupport\Workflow\Handler\Node\ResolveNode;
use PHPUnit\Framework\TestCase;

/**
 * The node factory only constructs; ResolveNode checks in the package that the object can be run.
 */
class ResolveNodeTest extends TestCase
{
    public function testCallableObjectBecomesAClosureThatRunsTheNode(): void
    {
        $node = new class {
            public function __invoke(ProcessContext $context): NodeResult
            {
                return NodeResult::ok([]);
            }
        };

        $closure = (new ResolveNode())($node);

        $this->assertInstanceOf(\Closure::class, $closure);
        $result = $closure($this->createStub(ProcessContext::class));
        $this->assertInstanceOf(NodeResult::class, $result);
    }

    public function testClosureObjectIsAcceptedAsNode(): void
    {
        $node = static fn(ProcessContext $context): NodeResult => NodeResult::ok([]);

        $this->assertInstanceOf(\Closure::class, (new ResolveNode())($node));
    }

    public function testObjectWithoutInvokeIsAConstructionErrorNamingTheClass(): void
    {
        $node = new class {
        };

        $this->expectException(ConstructionException::class);
        $this->expectExceptionMessage($node::class);

        (new ResolveNode())($node);
    }
}
