<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Tests\Integration\Runner;

use JardisSupport\Contract\Kernel\ResponseStatus;
use JardisSupport\Workflow\Data\NodeResult;
use JardisSupport\Workflow\Data\ProcessContext;
use JardisSupport\Workflow\Data\ProcessDefinition;
use JardisSupport\Workflow\Data\ProcessOutcome;
use JardisSupport\Workflow\Handler\Node\MapResponse;
use JardisSupport\Workflow\Tests\Support\FakeDomainResponse;
use JardisSupport\Workflow\Tests\Support\RunnerTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * A 4xx passed through a call node reaches the outcome of the run as plain data: the status, the message
 * key the node declared for that status (else the one the callee's form carries), the callee's context
 * unpacked from its form (`tests/Fixture/envelope` holds the forms as recorded). The wire form built
 * from it is the door's business (`jardissupport/response`).
 */
class CallNodeEnvelopeTest extends RunnerTestCase
{
    private const CALL = 'Node\\Call';

    /**
     * Per form: fixture, status, the key the callee's form carries, the context the outcome carries.
     *
     * @return array<string, array{string, ResponseStatus, string, array<string, mixed>}>
     */
    public static function forms(): array
    {
        return [
            '400 validation' => ['validation', ResponseStatus::ValidationError, '', ['fields' => [
                ['field' => 'email', 'reason' => 'missing', 'message' => 'Required', 'messageKey' => 'order.email.missing'],
                ['field' => 'items[1].qty', 'reason' => 'min', 'message' => 'Must be at least 1', 'messageKey' => 'order.qty.min'],
            ]]],
            '404 notFound' => ['notFound', ResponseStatus::NotFound, '', ['orderNumber' => 'ORD-404']],
            '409 concurrencyConflict' => ['conflict', ResponseStatus::Conflict, '', [
                'reason' => 'stale',
                'aggregate' => 'order',
                'violated' => ['uq_order_number'],
                'orderNumber' => 'ORD-2026-0001',
                'expectedVersion' => 3,
            ]],
            '422 ruleViolation' => ['ruleViolation', ResponseStatus::RuleViolation, 'order.rule.open', ['rule' => 'OrderMustBeOpen']],
        ];
    }

    /**
     * @param array<string, mixed> $context
     */
    #[DataProvider('forms')]
    public function testPassedThroughFormReachesTheOutcomeAsPlainContext(
        string $fixture,
        ResponseStatus $status,
        string $ownKey,
        array $context
    ): void {
        $outcome = $this->answer($status, $this->recordedData($fixture), false);

        $this->assertSame($status, $outcome->status());
        $this->assertSame($ownKey, $outcome->messageKey());
        $this->assertSame($context, $outcome->context());
        $this->assertEquals(new \stdClass(), $outcome->response(), 'the engine builds no failure form');
    }

    /**
     * @param array<string, mixed> $context
     */
    #[DataProvider('forms')]
    public function testDeclaredKeyBeatsTheKeyOfTheCallee(
        string $fixture,
        ResponseStatus $status,
        string $ownKey,
        array $context
    ): void {
        $outcome = $this->answer($status, $this->recordedData($fixture), true);

        $this->assertSame('registry.declared', $outcome->messageKey());
        $this->assertSame($context, $outcome->context(), 'the declaration changes the key only');
    }

    public function testCalleeKeyOfAFormWithoutOneDoesNotReachTheOutcome(): void
    {
        $callee = $this->recordedData('notFound') + ['messageKey' => 'callee.key'];

        $outcome = $this->answer(ResponseStatus::NotFound, $callee, false);

        $this->assertSame('', $outcome->messageKey());
        $this->assertSame(['orderNumber' => 'ORD-404'], $outcome->context());
    }

    public function testDeclarationForAnotherStatusLeavesTheCalleesKeyAlone(): void
    {
        $outcome = $this->answer(
            ResponseStatus::RuleViolation,
            $this->recordedData('ruleViolation'),
            true,
            ResponseStatus::NotFound
        );

        $this->assertSame('order.rule.open', $outcome->messageKey());
    }

    public function testOtherClientErrorPassesTheCalleesDataThroughAsContext(): void
    {
        $callee = ['anything' => 'stays', 'messageKey' => 'auth.denied', 'scope' => 'orders'];

        $outcome = $this->answer(ResponseStatus::Forbidden, $callee, false);

        $this->assertSame($callee, $outcome->context(), 'a key in the data stays where it is');
        $this->assertSame('', $outcome->messageKey());
    }

    public function testOtherClientErrorTakesTheDeclaredKeyAndKeepsItsContext(): void
    {
        $callee = ['messageKey' => 'auth.denied', 'scope' => 'orders'];

        $outcome = $this->answer(ResponseStatus::Unauthorized, $callee, true);

        $this->assertSame('registry.declared', $outcome->messageKey());
        $this->assertSame($callee, $outcome->context());
    }

    public function testNodeThatRejects401AnswersOnlyTheDeclaredKey(): void
    {
        $definition = ProcessDefinition::for('In', 'P')->node(self::CALL)
            ->rejects(ResponseStatus::Unauthorized, 'access.invalidCredentials')
            ->terminal()->build();

        $outcome = $this->runner()->run($definition, new \stdClass(), null, $this->factory([
            self::CALL => static fn(ProcessContext $c): NodeResult => NodeResult::reject(
                ResponseStatus::Unauthorized,
                '',
                [],
                ['Invalid credentials']
            ),
        ]));

        $this->assertSame(ResponseStatus::Unauthorized, $outcome->status());
        $this->assertSame(['Invalid credentials'], $outcome->errors());
        $this->assertSame('access.invalidCredentials', $outcome->messageKey());
        $this->assertSame([], $outcome->context());
    }

    public function testNodeThatRejects403WithoutADeclarationAnswersItsPayloadAndItsKey(): void
    {
        $definition = ProcessDefinition::for('In', 'P')->node(self::CALL)->terminal()->build();

        $outcome = $this->runner()->run($definition, new \stdClass(), null, $this->factory([
            self::CALL => static fn(ProcessContext $c): NodeResult => NodeResult::reject(
                ResponseStatus::Forbidden,
                'systemHealth.shift_forbidden',
                ['anything' => 'stays']
            ),
        ]));

        $this->assertSame('systemHealth.shift_forbidden', $outcome->messageKey());
        $this->assertSame(['anything' => 'stays'], $outcome->context());
    }

    /**
     * The recorded `data` as the callee answers it: an empty context arrives as an object.
     *
     * @return array<string, mixed>
     */
    private function recordedData(string $fixture): array
    {
        $path = dirname(__DIR__, 2) . '/Fixture/envelope/' . $fixture . '.json';
        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        $data = $decoded['body']['data'];
        if (array_key_exists('context', $data) && $data['context'] === []) {
            $data['context'] = new \stdClass();
        }

        return $data;
    }

    /**
     * Runs a one-node process whose node calls an operation answering $calleeData.
     *
     * @param array<string, mixed> $calleeData
     */
    private function answer(
        ResponseStatus $status,
        array $calleeData,
        bool $declare,
        ?ResponseStatus $declaredFor = null
    ): ProcessOutcome {
        $definition = ProcessDefinition::for('In', 'P')->node(self::CALL);
        if ($declare) {
            $definition->rejects($declaredFor ?? $status, 'registry.declared');
        }
        $definition = $definition->terminal()->build();

        $outcome = $this->runner()->run($definition, new \stdClass(), null, $this->factory([
            self::CALL => static fn(ProcessContext $c): NodeResult => (new MapResponse())(
                new FakeDomainResponse($status->value, $calleeData)
            ),
        ]));

        $this->assertSame($status, $outcome->status());

        return $outcome;
    }
}
