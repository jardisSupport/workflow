<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Tests\Integration\Outcome;

use JardisSupport\Contract\Kernel\ResponseStatus;
use JardisSupport\Workflow\Data\NodeResult;
use JardisSupport\Workflow\Data\ProcessContext;
use JardisSupport\Workflow\Data\ProcessDefinition;
use JardisSupport\Workflow\Data\Route;
use JardisSupport\Workflow\Handler\Node\MapResponseInto;
use JardisSupport\Workflow\Tests\Support\FakeDomainResponse;
use JardisSupport\Workflow\Tests\Support\RunnerTestCase;

/**
 * The engine side of the nine consumer-proof cases of the process picture (prozess-bild, PRD 4.6): the answer of the
 * run carries the `data` part of the bytes the generated door recorded, a reject and a throw stay as the engine gives
 * them.
 *
 * Origin of the fixtures `tests/Fixture/prozess-bild/case-{n}.json`: the status and the `data` of the recorded
 * answers `docs/prozess-bild/nachweis/E3-P2-{n}.body.txt` of the Builder (commit 42e10ad1d), taken over by script,
 * not retyped. Each graph below is the graph the generated handler of that case declares (`answerFrom`,
 * `MapResponseInto`, `rejects`, `NodeResult::reject`, a throw in the body), with the node bodies reduced to what the
 * case needs; the node ids are the class names of the generated nodes.
 *
 * What the engine owns and what the door owns:
 * - 200 (cases 1, 2, 3, 9): the engine answers the flat fields of the named entries. The `@type` marker, the
 *   formatting of a `DateTimeImmutable` to the wire string and the cut of a row to its outer form are the door's
 *   (`jardissupport/response`); the test compares the data without `@type`, and the `takenAt` of case 9 formatted as
 *   the wire writes it.
 * - 404, 409, 422 (cases 5, 6, 7): the engine answers status, message key and the plain context; the failure form
 *   of the wire (`@type`, `reason`, nesting of `context`) is built by the door from them.
 * - 500 (case 8): the engine answers no 500; the throw of the node leaves `run()` unchanged, the door maps it.
 * - 400 (case 4, input check): the check stands in front of the engine, a failed check never reaches it. There is no
 *   engine path to prove, none is invented; the fixture is recorded and its shape is checked below.
 */
class ProzessBildWireDataTest extends RunnerTestCase
{
    /**
     * @return array{status: int, data: array<string, mixed>}
     */
    private function wire(int $case): array
    {
        $file = dirname(__DIR__, 2) . '/Fixture/prozess-bild/case-' . $case . '.json';
        $recorded = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($recorded);
        $this->assertIsInt($recorded['status']);
        $this->assertIsArray($recorded['data']);

        return ['status' => $recorded['status'], 'data' => $recorded['data']];
    }

    /**
     * The `data` of a recorded answer as the engine answers it: the door's `@type` marker left out.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function withoutType(array $data): array
    {
        return array_diff_key($data, ['@type' => true]);
    }

    /**
     * @return class-string
     */
    private function parcelIdentifierClass(): string
    {
        return get_class(new class {
            public string $parcelIdentifier = '';
        });
    }

    public function testEveryCaseOfTheTableHasItsRecordedAnswer(): void
    {
        $expected = [1 => 200, 2 => 200, 3 => 200, 4 => 400, 5 => 404, 6 => 409, 7 => 422, 8 => 500, 9 => 200];

        foreach ($expected as $case => $status) {
            $this->assertSame($status, $this->wire($case)['status'], 'case ' . $case);
        }
        // case 4: the door's check answers before the engine runs; its form is the validation form of the wire
        $this->assertSame('validation', $this->wire(4)['data']['@type']);
    }

    public function testCase1AnIdentifierOfTheCallIsTheAnswer(): void
    {
        $wire = $this->wire(1);
        $call = 'Case1\\RemoveParcelDirect';
        $definition = ProcessDefinition::for('In', 'EndsOnIdentifier')
            ->transactional(true)
            ->node($call)->input('removal')->terminal(Route::OnTrue)
            ->answerFrom([$call])
            ->build();
        $into = $this->parcelIdentifierClass();
        // the callee answers more than the typed reference knows: `@type` and `id` fall away
        $calleeData = ['@type' => 'removeParcel', 'id' => 7] + $this->withoutType($wire['data']);

        $outcome = $this->runner()->run($definition, new \stdClass(), $this->factory([
            $call => static fn(ProcessContext $c): NodeResult => (new MapResponseInto())(
                new FakeDomainResponse(200, $calleeData),
                $into
            ),
        ]));

        $this->assertSame(ResponseStatus::from($wire['status']), $outcome->status());
        $this->assertSame($this->withoutType($wire['data']), get_object_vars($outcome->response()));
    }

    public function testCase2TheFieldsOfAnObjectValueAreTheAnswer(): void
    {
        $wire = $this->wire(2);
        $data = $this->withoutType($wire['data']);
        $node = 'Case2\\SummarizeWeight';
        $definition = ProcessDefinition::for('In', 'EndsOnFields')
            ->node($node)->terminal(Route::OnTrue)
            ->answerFrom([$node])
            ->build();
        $summary = new class ($data['count'], $data['note']) {
            public function __construct(public int $count, public ?string $note)
            {
            }
        };

        $outcome = $this->runner()->run($definition, new \stdClass(), $this->factory([
            $node => static fn(ProcessContext $c): NodeResult => NodeResult::ok($summary),
        ]));

        $this->assertSame(ResponseStatus::from($wire['status']), $outcome->status());
        $this->assertSame($data, get_object_vars($outcome->response()));
    }

    public function testCase3ANodeWithoutDataAnswersNoFields(): void
    {
        $wire = $this->wire(3);
        $node = 'Case3\\NoteWeight';
        $definition = ProcessDefinition::for('In', 'EndsOnNoFields')
            ->node($node)->terminal(Route::OnTrue)
            ->answerFrom()
            ->build();

        $outcome = $this->runner()->run($definition, new \stdClass(), $this->factory([
            $node => static fn(ProcessContext $c): NodeResult => NodeResult::route(Route::OnTrue),
        ]));

        $this->assertSame(ResponseStatus::from($wire['status']), $outcome->status());
        $this->assertSame([], $this->withoutType($wire['data']));
        $this->assertSame([], get_object_vars($outcome->response()));
    }

    public function testCase5ARejectOfTheNodeCarriesStatusKeyAndContext(): void
    {
        $wire = $this->wire(5);
        $load = 'Case5\\LoadParcel';
        $decide = 'Case5\\DecideFound';
        $card = 'Case5\\ParcelCard';
        $definition = ProcessDefinition::for('In', 'FindsParcelOrRefusesNotFound')
            ->node($load)->onTrue($decide)
            ->node($decide)->onTrue($card)->rejects(ResponseStatus::NotFound, $wire['data']['messageKey'])
            ->node($card)->terminal(Route::OnTrue)
            ->answerFrom([$card])
            ->build();

        $outcome = $this->runner()->run($definition, new \stdClass(), $this->factory([
            $load => static fn(ProcessContext $c): NodeResult => NodeResult::ok([]),
            $decide => static fn(ProcessContext $c): NodeResult => NodeResult::reject(
                ResponseStatus::NotFound,
                $wire['data']['messageKey'],
                $wire['data']['context']
            ),
            $card => static fn(ProcessContext $c): NodeResult => NodeResult::ok(['label' => 'never reached']),
        ]));

        $this->assertTrue($outcome->isFailed());
        $this->assertSame(ResponseStatus::from($wire['status']), $outcome->status());
        $this->assertSame($wire['data']['messageKey'], $outcome->messageKey());
        $this->assertSame($wire['data']['context'], $outcome->context());
        $this->assertSame([], get_object_vars($outcome->response()));
    }

    public function testCase6AConflictOfTheCallIsRejectedWithItsFieldsAndTheKeyOfTheNode(): void
    {
        $wire = $this->wire(6);
        $data = $wire['data'];
        $call = 'Case6\\RegisterUniqueParcel';
        $definition = ProcessDefinition::for('In', 'RegistersParcelWithUniqueLabel')
            ->node($call)->input('parcel')->terminal(Route::OnTrue)
            ->rejects(ResponseStatus::Conflict, $data['messageKey'])
            ->answerFrom([$call])
            ->build();
        // the inner door answers its own form: the key is the node's, not the callee's
        $calleeData = array_diff_key($data, ['messageKey' => true]);
        $status = $wire['status'];
        $into = $this->parcelIdentifierClass();

        $outcome = $this->runner()->run($definition, new \stdClass(), $this->factory([
            $call => static fn(ProcessContext $c): NodeResult => (new MapResponseInto())(
                new FakeDomainResponse($status, $calleeData),
                $into
            ),
        ]));

        $this->assertSame(ResponseStatus::from($wire['status']), $outcome->status());
        $this->assertSame($data['messageKey'], $outcome->messageKey());
        $this->assertSame(
            ['reason' => $data['reason'], 'aggregate' => $data['aggregate'], 'violated' => $data['violated']]
                + $data['context'],
            $outcome->context()
        );
    }

    public function testCase7TheOwnRejectOfANodeAnswersTheRuleViolationWithoutRule(): void
    {
        $wire = $this->wire(7);
        $data = $wire['data'];
        $rule = 'Case7\\CheckParcelFitsCapacity';
        $confirm = 'Case7\\ConfirmParcelNotOnHold';
        $definition = ProcessDefinition::for('In', 'RefusesParcelByRuleAndNode')
            ->node($rule)->onTrue($confirm)
            ->node($confirm)->terminal(Route::OnTrue)->rejects(ResponseStatus::RuleViolation, $data['messageKey'])
            ->answerFrom()
            ->build();

        $outcome = $this->runner()->run($definition, new \stdClass(), $this->factory([
            $rule => static fn(ProcessContext $c): NodeResult => NodeResult::route(Route::OnTrue),
            $confirm => static fn(ProcessContext $c): NodeResult => NodeResult::reject(
                ResponseStatus::RuleViolation,
                $data['messageKey'],
                $data['context']
            ),
        ]));

        $this->assertSame(ResponseStatus::from($wire['status']), $outcome->status());
        $this->assertSame($data['messageKey'], $outcome->messageKey());
        // the node named no rule: the engine's context carries none, the door writes the empty `rule` of the wire
        $this->assertSame('', $data['rule']);
        $this->assertSame($data['context'], $outcome->context());
    }

    public function testCase8AThrowOfTheBodyLeavesTheEngineUnchangedAndTheWireAnswersNoData(): void
    {
        $wire = $this->wire(8);
        $node = 'Case8\\MeasureWeight';
        $definition = ProcessDefinition::for('In', 'WeighsParcelWithFailingBody')
            ->node($node)->terminal(Route::OnTrue)
            ->answerFrom([$node])
            ->build();
        $failure = new \RuntimeException('Measuring the weight failed technically.');

        $this->assertSame(500, $wire['status']);
        $this->assertSame([], $wire['data']);
        try {
            $this->runner()->run($definition, new \stdClass(), $this->factory([
                $node => static function (ProcessContext $c) use ($failure): NodeResult {
                    throw $failure;
                },
            ]));
            $this->fail('the engine answers no 500, the throw must leave run()');
        } catch (\RuntimeException $thrown) {
            $this->assertSame($failure, $thrown);
        }
    }

    public function testCase9ADatetimeAndRowsOfTheValueAreTheAnswer(): void
    {
        $wire = $this->wire(9);
        $data = $this->withoutType($wire['data']);
        $node = 'Case9\\ReportParcels';
        $definition = ProcessDefinition::for('In', 'EndsOnDatetimeAndRows')
            ->node($node)->terminal(Route::OnTrue)
            ->answerFrom([$node])
            ->build();
        $report = new class (new \DateTimeImmutable($data['takenAt']), $data['parcels']) {
            /**
             * @param list<array<string, mixed>> $parcels
             */
            public function __construct(public \DateTimeImmutable $takenAt, public array $parcels)
            {
            }
        };

        $outcome = $this->runner()->run($definition, new \stdClass(), $this->factory([
            $node => static fn(ProcessContext $c): NodeResult => NodeResult::ok($report),
        ]));

        $answer = get_object_vars($outcome->response());
        $this->assertInstanceOf(\DateTimeImmutable::class, $answer['takenAt']);
        $answer['takenAt'] = $answer['takenAt']->format(\DateTimeInterface::ATOM);
        $this->assertSame(ResponseStatus::from($wire['status']), $outcome->status());
        $this->assertSame($data, $answer);
    }
}
