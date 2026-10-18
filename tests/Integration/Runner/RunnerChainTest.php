<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Tests\Integration\Runner;

use JardisSupport\Contract\Kernel\ResponseStatus;
use JardisSupport\Workflow\Data\NodeResult;
use JardisSupport\Workflow\Data\ProcessContext;
use JardisSupport\Workflow\Data\ProcessDefinition;
use JardisSupport\Workflow\Data\Route;
use JardisSupport\Workflow\Exception\ConstructionException;
use JardisSupport\Workflow\Exception\LoopLimitExceededException;
use JardisSupport\Workflow\Tests\Support\Process\Checkout\CheckoutContext;
use JardisSupport\Workflow\Tests\Support\Process\Checkout\Command\ReserveStock;
use JardisSupport\Workflow\Tests\Support\Process\Checkout\Response\ReserveStockResponse;
use JardisSupport\Workflow\Tests\Support\RunnerTestCase;

/**
 * The node chain GuardLoop, CollectEvents, HydrateResponse around real nodes,
 * driven through the runner (W1 + B1).
 */
class RunnerChainTest extends RunnerTestCase
{
    private const A = 'Node\\A';
    private const B = 'Node\\B';

    public function testResponseDeclarationBuildsTheTypedResponseFromNamedConstructorArguments(): void
    {
        $definition = ProcessDefinition::for('In', 'Checkout')
            ->node(ReserveStock::class)->response(ReserveStockResponse::class, 'reservationId', 'quantity')->terminal()
            ->build();

        $outcome = $this->runner()->run(
            $definition,
            new \stdClass(),
            null,
            $this->factory([ReserveStock::class => new ReserveStock()])
        );

        $response = $outcome->response();
        $this->assertSame(ResponseStatus::Success, $outcome->status());
        $this->assertInstanceOf(ReserveStockResponse::class, $response);
        $this->assertSame('R-1', $response->reservationId);
        $this->assertSame(3, $response->quantity);
        $this->assertSame('none', $response->note, 'optional argument keeps its default');
    }

    public function testResponseClassIsDeclaredNotDerivedFromTheNodeName(): void
    {
        $definition = ProcessDefinition::for('In', 'P')
            ->node(self::A)->response(ReserveStockResponse::class)->terminal()
            ->build();

        $outcome = $this->runner()->run(
            $definition,
            new \stdClass(),
            null,
            $this->factory([self::A => static fn(ProcessContext $c): NodeResult => NodeResult::ok(
                ['reservationId' => 'R-9', 'quantity' => 1, 'internal' => 'hidden']
            )])
        );

        $response = $outcome->response();
        $this->assertSame('Node\\A', self::A, 'the node class name follows no naming rule');
        $this->assertInstanceOf(ReserveStockResponse::class, $response);
        $this->assertSame('R-9', $response->reservationId);
        $this->assertSame(1, $response->quantity, 'no field named = all constructor fields');
    }

    public function testResponseWithoutDeclarationKeepsTheDataUnchanged(): void
    {
        $definition = ProcessDefinition::for('In', 'Checkout')->node(ReserveStock::class)->terminal()->build();

        $outcome = $this->runner()->run(
            $definition,
            new \stdClass(),
            null,
            $this->factory([ReserveStock::class => new ReserveStock()])
        );

        $this->assertEquals((object) ['reservationId' => 'R-1', 'quantity' => 3, 'internal' => 'hidden'], $outcome->response());
    }

    public function testMissingRequiredResponseFieldIsAConstructionException(): void
    {
        $definition = ProcessDefinition::for('In', 'Checkout')
            ->node(ReserveStock::class)->response(ReserveStockResponse::class, 'reservationId')->terminal()
            ->build();

        $outcome = $this->runner()->run(
            $definition,
            new \stdClass(),
            null,
            $this->factory([ReserveStock::class => new ReserveStock()])
        );

        $this->assertSame(ResponseStatus::InternalError, $outcome->status());
        $this->assertInstanceOf(ConstructionException::class, $outcome->cause());
        $this->assertStringContainsString('quantity', $outcome->cause()->getMessage());
    }

    public function testDeclaredResponseClassThatDoesNotExistIsAConstructionException(): void
    {
        $definition = ProcessDefinition::for('In', 'P')->node(self::A)->response('Missing\\NoSuchResponse', 'x')->terminal()->build();

        $outcome = $this->runner()->run(
            $definition,
            new \stdClass(),
            null,
            $this->factory([self::A => static fn(ProcessContext $c): NodeResult => NodeResult::ok(['x' => 1])])
        );

        $this->assertSame(ResponseStatus::InternalError, $outcome->status());
        $this->assertInstanceOf(ConstructionException::class, $outcome->cause());
    }

    public function testObjectDataIsNotHydratedAgain(): void
    {
        $object = new \stdClass();
        $definition = ProcessDefinition::for('In', 'P')->node(self::A)->response(ReserveStockResponse::class, 'reservationId')->terminal()->build();

        $outcome = $this->runner()->run(
            $definition,
            new \stdClass(),
            null,
            $this->factory([self::A => static fn(ProcessContext $c): NodeResult => NodeResult::ok($object)])
        );

        $this->assertSame($object, $outcome->response());
    }

    public function testLoopGuardIsOutermostAndStopsBeforeTheNodeIsBuilt(): void
    {
        $definition = ProcessDefinition::for('In', 'P')
            ->loopLimit(2)
            ->node(self::A)->onTrue(self::B)
            ->node(self::B)->onTrue(self::A)
            ->build();
        $built = [];
        $ok = static fn(ProcessContext $c): NodeResult => NodeResult::ok(['n' => 1]);

        $outcome = $this->runner()->run(
            $definition,
            new \stdClass(),
            null,
            $this->factory([self::A => $ok, self::B => $ok], $built)
        );

        $this->assertSame(ResponseStatus::InternalError, $outcome->status());
        $cause = $outcome->cause();
        $this->assertInstanceOf(LoopLimitExceededException::class, $cause);
        $this->assertSame(2, $cause->getLimit());

        $this->assertSame([self::A, self::B], $built, 'the third node is never asked for');
    }

    public function testDeclaredContextClassCarriesInputActorAndDepth(): void
    {
        $definition = ProcessDefinition::for('In', 'Checkout')->context(CheckoutContext::class)
            ->node(self::A)->terminal()->build();
        $seen = null;
        $input = new \stdClass();
        $actor = new \stdClass();

        $this->runner()->run($definition, $input, $actor, $this->factory([
            self::A => static function (ProcessContext $c) use (&$seen): NodeResult {
                $seen = $c;

                return NodeResult::ok([]);
            },
        ]), new ProcessContext(new \stdClass(), null, 1));

        $this->assertInstanceOf(CheckoutContext::class, $seen);
        $this->assertSame($input, $seen->input());
        $this->assertSame($actor, $seen->actor());
        $this->assertSame('checkout:2', $seen->marker(), 'depth is the parent depth plus one');
    }

    public function testContextClassNotExtendingTheBaseIsRejected(): void
    {
        $definition = ProcessDefinition::for('In', 'P')->context(\stdClass::class)->node(self::A)->terminal()->build();

        $outcome = $this->runner()->run($definition, new \stdClass(), null, $this->factory([]));

        $this->assertSame(ResponseStatus::InternalError, $outcome->status());
        $this->assertInstanceOf(ConstructionException::class, $outcome->cause());
    }

    public function testOutermostRunHasDepthZero(): void
    {
        $definition = ProcessDefinition::for('In', 'P')->node(self::A)->terminal()->build();
        $depth = -1;

        $this->runner()->run($definition, new \stdClass(), null, $this->factory([
            self::A => static function (ProcessContext $c) use (&$depth): NodeResult {
                $depth = $c->depth();

                return NodeResult::route(Route::OnTrue, []);
            },
        ]));

        $this->assertSame(0, $depth);
    }
}
