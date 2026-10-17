<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Tests\Integration\Start;

use JardisSupport\Contract\Kernel\ResponseStatus;
use JardisSupport\Workflow\Data\NodeResult;
use JardisSupport\Workflow\Data\ProcessContext;
use JardisSupport\Workflow\Data\ProcessDefinition;
use JardisSupport\Workflow\Tests\Support\Process\Order\OrderInput;
use JardisSupport\Workflow\Tests\Support\Process\Order\OrderLine;
use JardisSupport\Workflow\Tests\Support\Process\Order\OrderValidation;
use JardisSupport\Workflow\Tests\Support\TransactionTestCase;

/**
 * A declared actor class needs an actor: otherwise 401 before node and transaction. The start
 * never answers 403 (A-S4).
 */
class CheckActorTest extends TransactionTestCase
{
    private const NODE = 'Node\\Place';

    private function definition(bool $actor): ProcessDefinition
    {
        $definition = ProcessDefinition::for(OrderInput::class, 'PlaceOrder')->validate(OrderValidation::class);
        if ($actor) {
            $definition->actor(\stdClass::class);
        }

        return $definition->node(self::NODE)->terminal()->build();
    }

    public function testMissingActorAnswers401WithoutNodeOrTransaction(): void
    {
        $built = [];

        $outcome = $this->runnerOn()->run(
            $this->definition(true),
            new OrderInput('Ada', []),
            null,
            $this->factory([self::NODE => $this->writes('node')], $built)
        );

        $this->assertSame(ResponseStatus::Unauthorized, $outcome->status());
        $this->assertSame('process.actorMissing', $outcome->response()->messageKey);
        $this->assertSame([], $built);
        $this->assertSame([], $this->connection->calls());
        $this->assertSame([], $this->ledger->notes());
    }

    public function testPresentActorRunsTheProcess(): void
    {
        $outcome = $this->runnerOn()->run(
            $this->definition(true),
            new OrderInput('Ada', []),
            new \stdClass(),
            $this->factory([self::NODE => $this->writes('node')])
        );

        $this->assertSame(ResponseStatus::Success, $outcome->status());
    }

    public function testWithoutADeclaredActorClassNoActorIsNeeded(): void
    {
        $outcome = $this->runnerOn()->run(
            $this->definition(false),
            new OrderInput('Ada', []),
            null,
            $this->factory([self::NODE => $this->writes('node')])
        );

        $this->assertSame(ResponseStatus::Success, $outcome->status());
    }

    public function testTheStartNeverAnswers403(): void
    {
        $statuses = [];
        $inputs = [new OrderInput('Ada', []), new OrderInput(null, [new OrderLine('A', 0)])];
        foreach ($inputs as $input) {
            foreach ([null, new \stdClass()] as $actor) {
                $statuses[] = $this->runnerOn()->run(
                    $this->definition(true),
                    $input,
                    $actor,
                    $this->factory([self::NODE => static fn(ProcessContext $c): NodeResult => NodeResult::ok([])])
                )->status();
            }
        }

        $this->assertNotContains(ResponseStatus::Forbidden, $statuses);
        $this->assertContains(ResponseStatus::Unauthorized, $statuses);
        $this->assertContains(ResponseStatus::ValidationError, $statuses);
    }
}
