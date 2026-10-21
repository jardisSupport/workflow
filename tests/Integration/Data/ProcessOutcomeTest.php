<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Tests\Integration\Data;

use JardisSupport\Contract\Kernel\AnswerableOutcomeInterface;
use JardisSupport\Contract\Kernel\OutcomeInterface;
use JardisSupport\Contract\Kernel\ResponseStatus;
use JardisSupport\Workflow\Data\ProcessOutcome;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * ProcessOutcome: the success shape and the reject shape of the engine.
 */
class ProcessOutcomeTest extends TestCase
{
    public function testClassIsFinalAndReadonly(): void
    {
        $reflection = new ReflectionClass(ProcessOutcome::class);

        $this->assertTrue($reflection->isFinal());
        $this->assertTrue($reflection->isReadOnly());
    }

    public function testSuccessCarriesResponseAndEvents(): void
    {
        $response = new \stdClass();
        $event = new \stdClass();

        $outcome = new ProcessOutcome(ResponseStatus::Success, $response, [], [$event]);

        $this->assertSame(ResponseStatus::Success, $outcome->status());
        $this->assertSame($response, $outcome->response());
        $this->assertSame([], $outcome->errors());
        $this->assertSame([$event], $outcome->events());
        $this->assertNull($outcome->messageKey());
        $this->assertSame([], $outcome->context());
    }

    public function testRejectCarriesMessageKeyContextAndErrorsWithoutEvents(): void
    {
        $outcome = new ProcessOutcome(
            ResponseStatus::NotFound,
            new \stdClass(),
            ['no such order'],
            [],
            'order.notFound',
            ['orderNumber' => 'ORD-404']
        );

        $this->assertSame(ResponseStatus::NotFound, $outcome->status());
        $this->assertSame(['no such order'], $outcome->errors());
        $this->assertSame([], $outcome->events());
        $this->assertSame('order.notFound', $outcome->messageKey());
        $this->assertSame(['orderNumber' => 'ORD-404'], $outcome->context());
    }

    public function testOutcomeHasNeitherCauseNorDeclaredMessageKey(): void
    {
        $methods = array_map(
            static fn(\ReflectionMethod $method): string => $method->getName(),
            (new ReflectionClass(ProcessOutcome::class))->getMethods(\ReflectionMethod::IS_PUBLIC)
        );
        sort($methods);

        $this->assertSame(
            ['__construct', 'context', 'errors', 'events', 'isFailed', 'messageKey', 'response', 'status', 'withResponse'],
            $methods
        );
    }

    public function testWithResponseCopiesEveryOtherFieldAndLeavesTheOriginalUnchanged(): void
    {
        $old = new \stdClass();
        $new = new \stdClass();
        $event = new \stdClass();
        $original = new ProcessOutcome(
            ResponseStatus::RuleViolation,
            $old,
            ['too much'],
            [$event],
            'order.creditExceeded',
            ['limit' => 10]
        );

        $copy = $original->withResponse($new);

        $this->assertNotSame($original, $copy);
        $this->assertSame($new, $copy->response());
        $this->assertSame(ResponseStatus::RuleViolation, $copy->status());
        $this->assertSame(['too much'], $copy->errors());
        $this->assertSame([$event], $copy->events());
        $this->assertSame('order.creditExceeded', $copy->messageKey());
        $this->assertSame(['limit' => 10], $copy->context());
        $this->assertTrue($copy->isFailed());
        $this->assertSame($old, $original->response(), 'the original keeps its answer');
    }

    public function testWithResponseOnASuccessKeepsDefaults(): void
    {
        $copy = (new ProcessOutcome(ResponseStatus::Success, new \stdClass()))->withResponse($answer = new \stdClass());

        $this->assertSame($answer, $copy->response());
        $this->assertSame(ResponseStatus::Success, $copy->status());
        $this->assertSame([], $copy->errors());
        $this->assertSame([], $copy->events());
        $this->assertNull($copy->messageKey());
        $this->assertSame([], $copy->context());
        $this->assertFalse($copy->isFailed());
    }

    public function testOutcomeIsTheContractsOutcome(): void
    {
        $outcome = new ProcessOutcome(ResponseStatus::Success, new \stdClass());

        $this->assertInstanceOf(OutcomeInterface::class, $outcome);
        $this->assertInstanceOf(AnswerableOutcomeInterface::class, $outcome);
    }

    public function testIsFailedFollowsTheStatusLineOf400(): void
    {
        $failed = [];
        foreach (ResponseStatus::cases() as $status) {
            $failed[$status->name] = (new ProcessOutcome($status, new \stdClass()))->isFailed();
        }

        $this->assertSame(
            [
                'Success' => false,
                'Created' => false,
                'NoContent' => false,
                'ValidationError' => true,
                'Unauthorized' => true,
                'Forbidden' => true,
                'NotFound' => true,
                'MethodNotAllowed' => true,
                'Conflict' => true,
                'RuleViolation' => true,
                'InternalError' => true,
            ],
            $failed
        );
    }

    public function testARunThatRejectsEndsAsAFailedOutcome(): void
    {
        $rejected = new ProcessOutcome(
            ResponseStatus::RuleViolation,
            new \stdClass(),
            [],
            [],
            'order.creditExceeded'
        );

        $this->assertTrue($rejected->isFailed());
        $this->assertSame(ResponseStatus::RuleViolation, $rejected->status());
    }
}
