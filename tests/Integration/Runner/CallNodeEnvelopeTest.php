<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Tests\Integration\Runner;

use JardisSupport\Contract\Kernel\ResponseStatus;
use JardisSupport\Workflow\Data\NodeResult;
use JardisSupport\Workflow\Data\ProcessContext;
use JardisSupport\Workflow\Data\ProcessDefinition;
use JardisSupport\Workflow\Handler\Node\MapResponse;
use JardisSupport\Workflow\Handler\Response\BuildResponseData;
use JardisSupport\Workflow\Tests\Support\EnvelopeJson;
use JardisSupport\Workflow\Tests\Support\FakeDomainResponse;
use JardisSupport\Workflow\Tests\Support\RunnerTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * A 4xx passed through a call node reaches the process door in the form of the recorded envelope
 * (`tests/Fixture/envelope`), with the message key the node declared for that status - the
 * callee answers its form, the process answers the same form again, no form inside a form.
 */
class CallNodeEnvelopeTest extends RunnerTestCase
{
    private const CALL = 'Node\\Call';

    /**
     * @return array<string, array{string, ResponseStatus}>
     */
    public static function forms(): array
    {
        return [
            '400 validation' => ['validation', ResponseStatus::ValidationError],
            '404 notFound' => ['notFound', ResponseStatus::NotFound],
            '409 concurrencyConflict' => ['conflict', ResponseStatus::Conflict],
            '422 ruleViolation' => ['ruleViolation', ResponseStatus::RuleViolation],
        ];
    }

    #[DataProvider('forms')]
    public function testPassedThroughFormIsByteIdenticalToTheRecordedData(string $fixture, ResponseStatus $status): void
    {
        $recorded = $this->recordedData($fixture);

        $data = $this->answer($status, $recorded, false);

        $this->assertSame(EnvelopeJson::encode($recorded), EnvelopeJson::encode($data));
    }

    #[DataProvider('forms')]
    public function testDeclaredKeyJoinsTheRecordedDataAsTheLastMember(string $fixture, ResponseStatus $status): void
    {
        $recorded = $this->recordedData($fixture);
        $expected = $recorded;
        $expected['messageKey'] = 'registry.declared';

        $data = $this->answer($status, $recorded, true);

        $this->assertSame(EnvelopeJson::encode($expected), EnvelopeJson::encode($data));
    }

    public function testCalleeKeyOfAFormWithoutOneDoesNotReachTheAnswer(): void
    {
        $recorded = $this->recordedData('notFound');
        $callee = $recorded + ['messageKey' => 'callee.key'];

        $data = $this->answer(ResponseStatus::NotFound, $callee, false);

        $this->assertSame($recorded, $data);
    }

    public function testDeclaredKeyOverridesTheKeyOfTheCalleesRuleViolationInPlace(): void
    {
        $recorded = $this->recordedData('ruleViolation');

        $data = $this->answer(ResponseStatus::RuleViolation, $recorded, true);

        $this->assertSame(['@type', 'rule', 'messageKey', 'context'], array_keys($data));
        $this->assertSame('registry.declared', $data['messageKey']);
    }

    public function testDeclarationForAnotherStatusLeavesTheCalleesKeyAlone(): void
    {
        $recorded = $this->recordedData('ruleViolation');

        $data = $this->answer(ResponseStatus::RuleViolation, $recorded, true, ResponseStatus::NotFound);

        $this->assertEquals($recorded, $data);
    }

    public function testOtherClientErrorPassesTheCalleesDataThroughUnchanged(): void
    {
        $callee = ['anything' => 'stays', 'messageKey' => 'auth.denied', 'scope' => 'orders'];

        $data = $this->answer(ResponseStatus::Forbidden, $callee, false);

        $this->assertSame(EnvelopeJson::encode($callee), EnvelopeJson::encode($data));
    }

    public function testOtherClientErrorTakesTheDeclaredKeyInPlaceOrLast(): void
    {
        $withKey = ['messageKey' => 'auth.denied', 'scope' => 'orders'];
        $withoutKey = ['scope' => 'orders'];

        $replaced = $this->answer(ResponseStatus::Unauthorized, $withKey, true);
        $appended = $this->answer(ResponseStatus::Unauthorized, $withoutKey, true);

        $this->assertSame('{"messageKey":"registry.declared","scope":"orders"}', json_encode($replaced));
        $this->assertSame('{"scope":"orders","messageKey":"registry.declared"}', json_encode($appended));
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
        $this->assertSame(
            '{"messageKey":"access.invalidCredentials"}',
            json_encode((new BuildResponseData())($outcome, 'P'))
        );
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

        $this->assertSame(
            '{"anything":"stays","messageKey":"systemHealth.shift_forbidden"}',
            json_encode((new BuildResponseData())($outcome, 'P'))
        );
    }

    /**
     * The recorded `data` as the callee answers it and the process encodes it: an empty context is an object.
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
     * Runs a one-node process whose node calls an operation answering $calleeData, and returns the
     * `data` of the process envelope.
     *
     * @param array<string, mixed> $calleeData
     * @return array<string, mixed>
     */
    private function answer(
        ResponseStatus $status,
        array $calleeData,
        bool $declare,
        ?ResponseStatus $declaredFor = null
    ): array {
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

        return (new BuildResponseData())($outcome, 'P');
    }
}
