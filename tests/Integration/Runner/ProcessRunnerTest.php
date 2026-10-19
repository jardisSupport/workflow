<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Tests\Integration\Runner;

use JardisSupport\Contract\Kernel\ResponseStatus;
use JardisSupport\Workflow\Data\NodeResult;
use JardisSupport\Workflow\Data\ProcessContext;
use JardisSupport\Workflow\Data\ProcessDefinition;
use JardisSupport\Workflow\Tests\Support\RunnerTestCase;

/**
 * The bare engine: it answers a finished run only. What a node throws is not caught, not mapped, not logged.
 */
class ProcessRunnerTest extends RunnerTestCase
{
    private const A = 'Node\\A';
    private const B = 'Node\\B';

    public function testAThrowingNodeIsThrownOnNotMapped(): void
    {
        $boom = new \RuntimeException('boom');
        $definition = ProcessDefinition::for('In', 'P')
            ->node(self::A)->onTrue(self::B)
            ->node(self::B)->terminal()
            ->build();
        $built = [];

        try {
            $this->runner()->run($definition, new \stdClass(), null, $this->factory([
                self::A => static function (ProcessContext $c) use ($boom): NodeResult {
                    throw $boom;
                },
            ], $built));
            $this->fail('the throw of a node must leave the runner');
        } catch (\RuntimeException $caught) {
            $this->assertSame($boom, $caught, 'the very throw of the node, not a copy and no 500 outcome');
        }

        $this->assertSame([self::A], $built, 'the run ends at the throw');
    }

    public function testASuccessfulRunAnswersTheDataOfTheLastNode(): void
    {
        $definition = ProcessDefinition::for('In', 'P')->node(self::A)->terminal()->build();

        $outcome = $this->runner()->run($definition, new \stdClass(), null, $this->factory([
            self::A => static fn(ProcessContext $c): NodeResult => NodeResult::ok(['id' => 7]),
        ]));

        $this->assertSame(ResponseStatus::Success, $outcome->status());
        $this->assertEquals((object) ['id' => 7], $outcome->response());
        $this->assertNull($outcome->messageKey());
        $this->assertSame([], $outcome->context());
    }
}
