<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Tests\Integration\Response;

use JardisSupport\Contract\Kernel\ResponseStatus;
use JardisSupport\Contract\Repository\Exception\TransientConflictException;
use JardisSupport\Workflow\Data\NodeResult;
use JardisSupport\Workflow\Data\ProcessContext;
use JardisSupport\Workflow\Data\ProcessDefinition;
use JardisSupport\Workflow\Data\ProcessOutcome;
use JardisSupport\Workflow\Handler\Response\BuildResponseData;
use JardisSupport\Workflow\ProcessRunner;
use JardisSupport\Workflow\Tests\Support\Process\Order\OrderInput;
use JardisSupport\Workflow\Tests\Support\Process\Order\OrderValidation;
use JardisSupport\Workflow\Tests\Support\TransactionTestCase;

/**
 * The failures of the package's own layers (input, actor, thrown) never put their message key on
 * the wire: the key stays on the form / response object, `data` carries the form alone or nothing.
 */
class LayerFailuresOnTheWireTest extends TransactionTestCase
{
    private const NODE = 'Node\\A';

    /**
     * @return array<string, mixed>
     */
    private function data(ProcessOutcome $outcome): array
    {
        return (new BuildResponseData())($outcome, 'P');
    }

    public function testMissingActorAnswers401WithEmptyData(): void
    {
        $definition = ProcessDefinition::for(OrderInput::class, 'P')->actor(\stdClass::class)
            ->node(self::NODE)->terminal()->build();

        $outcome = $this->runnerOn()->run($definition, new OrderInput('Ada', []), null, $this->factory([
            self::NODE => $this->writes('lost'),
        ]));

        $this->assertSame(ResponseStatus::Unauthorized, $outcome->status());
        $this->assertSame('process.actorMissing', $outcome->response()->messageKey, 'the key stays internal');
        $this->assertSame([], $this->data($outcome));
        $this->assertSame('{}', json_encode((object) $this->data($outcome)));
    }

    public function testInvalidInputAnswers400WithTheFormAlone(): void
    {
        $definition = ProcessDefinition::for(OrderInput::class, 'P')->validate(OrderValidation::class)
            ->node(self::NODE)->terminal()->build();

        $outcome = $this->runnerOn()->run($definition, new OrderInput(null, []), null, $this->factory([
            self::NODE => $this->writes('lost'),
        ]));

        $this->assertSame(ResponseStatus::ValidationError, $outcome->status());
        $this->assertSame(['@type', 'fields'], array_keys($this->data($outcome)));
    }

    public function testTransientConflictAnswers409WithTheFormAlone(): void
    {
        $definition = ProcessDefinition::for('In', 'P')->node(self::NODE)->terminal()->build();

        $outcome = $this->runnerOn()->run($definition, new \stdClass(), null, $this->factory([
            self::NODE => static function (ProcessContext $c): NodeResult {
                throw new TransientConflictException('deadlock', '40001');
            },
        ]));

        $this->assertSame(ResponseStatus::Conflict, $outcome->status());
        $this->assertSame(['@type', 'reason', 'aggregate', 'context', 'violated'], array_keys($this->data($outcome)));
    }

    public function testLoopLimitAnswers500WithoutData(): void
    {
        $definition = ProcessDefinition::for('In', 'P')->loopLimit(2)
            ->node(self::NODE)->onTrue(self::NODE)->build();

        $outcome = $this->runnerOn()->run($definition, new \stdClass(), null, $this->factory([
            self::NODE => $this->writes('lost', ['n' => 1]),
        ]));

        $this->assertSame(ResponseStatus::InternalError, $outcome->status());
        $this->assertSame([], $this->data($outcome));
    }

    public function testMissingConnectionAnswers500WithoutData(): void
    {
        $definition = ProcessDefinition::for('In', 'P')->node(self::NODE)->terminal()->build();

        $outcome = (new ProcessRunner(null, OrderValidation::validator()))->run(
            $definition,
            new \stdClass(),
            null,
            $this->factory([self::NODE => $this->writes('lost')])
        );

        $this->assertSame(ResponseStatus::InternalError, $outcome->status());
        $this->assertSame([], $this->data($outcome));
    }
}
