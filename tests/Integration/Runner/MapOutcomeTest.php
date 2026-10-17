<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Tests\Integration\Runner;

use JardisSupport\Contract\Kernel\ResponseStatus;
use JardisSupport\Workflow\Data\NodeResult;
use JardisSupport\Workflow\Data\ProcessContext;
use JardisSupport\Workflow\Data\ProcessDefinition;
use JardisSupport\Workflow\Data\ProcessOutcome;
use JardisSupport\Workflow\Data\Route;
use JardisSupport\Workflow\Handler\Node\MapOutcome;
use JardisSupport\Workflow\Handler\Response\BuildResponseData;
use JardisSupport\Workflow\Tests\Support\Process\Order\OrderInput;
use JardisSupport\Workflow\Tests\Support\Process\Order\OrderValidation;
use JardisSupport\Workflow\Tests\Support\TransactionTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * MapOutcome: the outcome of an inner run becomes the result of the calling node - per status class,
 * through the real runner (sub-process node of {@see TransactionTestCase::subProcess()}).
 */
class MapOutcomeTest extends TransactionTestCase
{
    private const OUTER = 'Outer\\Call';
    private const INNER = 'Inner\\Step';

    /**
     * @param array<string, callable(ProcessContext): NodeResult> $innerNodes
     * @return array{ProcessOutcome, ProcessOutcome}
     */
    private function runBoth(ProcessDefinition $inner, array $innerNodes, ?ProcessDefinition $outer = null): array
    {
        $runner = $this->runnerOn();
        $innerOutcome = $runner->run($inner, new \stdClass(), null, $this->factory($innerNodes));
        $outerOutcome = $runner->run(
            $outer ?? ProcessDefinition::for('In', 'Outer')->node(self::OUTER)->terminal()->build(),
            new \stdClass(),
            null,
            $this->factory([self::OUTER => $this->subProcess($runner, $inner, $this->factory($innerNodes))])
        );

        return [$innerOutcome, $outerOutcome];
    }

    /**
     * @return array<string, mixed>
     */
    private function wire(ProcessOutcome $outcome, string $process): array
    {
        return (new BuildResponseData())($outcome, $process);
    }

    private function innerDefinition(?ResponseStatus $status = null, string $declaredKey = ''): ProcessDefinition
    {
        $definition = ProcessDefinition::for('In', 'Inner')->node(self::INNER);
        if ($status !== null) {
            $definition = $definition->rejects($status, $declaredKey);
        }

        return $definition->terminal()->build();
    }

    public function testSuccessCarriesTheResponseAndAllEventsOfTheInnerRun(): void
    {
        $first = new \stdClass();
        $first->name = 'first';
        $second = new \stdClass();
        $second->name = 'second';

        [$inner, $outer] = $this->runBoth($this->innerDefinition(), [
            self::INNER => static fn(ProcessContext $c): NodeResult => NodeResult::ok(['id' => 7])
                ->withEvents($first, $second),
        ]);

        $this->assertSame(ResponseStatus::Success, $outer->status());
        $this->assertSame(['id' => 7], (array) $outer->response());
        $this->assertSame([$first, $second], $outer->events());
        $this->assertSame($inner->events(), $outer->events());
    }

    public function testInner400AnswersTheSameValidationFormWithTheFieldKeysAtTheOuterCall(): void
    {
        $runner = $this->runnerOn();
        $inner = ProcessDefinition::for(OrderInput::class, 'Inner')
            ->validate(OrderValidation::class)
            ->node(self::INNER)->terminal()->build();
        $innerNodes = $this->factory([self::INNER => $this->writes('never')]);
        $outerDefinition = ProcessDefinition::for('In', 'Outer')->node(self::OUTER)->terminal()->build();

        $innerOutcome = $runner->run($inner, new OrderInput(null, []), null, $innerNodes);
        $outerOutcome = $runner->run($outerDefinition, new \stdClass(), null, $this->factory([
            self::OUTER => $this->subProcess($runner, $inner, $innerNodes, null, new OrderInput(null, [])),
        ]));

        $this->assertSame(ResponseStatus::ValidationError, $outerOutcome->status());
        $wire = $this->wire($outerOutcome, 'Outer');
        $this->assertSame('validation', $wire['@type']);
        $this->assertSame('customer', $wire['fields'][0]['field']);
        $this->assertSame('validation.missing', $wire['fields'][0]['messageKey'], 'the key of the finding travels');
        $this->assertSame($this->wire($innerOutcome, 'Inner'), $wire, 'the wire form is the one of the inner answer');
        $this->assertSame($innerOutcome->errors(), $outerOutcome->errors());
        $this->assertSame([], $this->ledger->notes());
    }

    public function testInner400FieldPathsMoveBelowTheOuterInputPropertyOfTheCallingNode(): void
    {
        $runner = $this->runnerOn();
        $inner = ProcessDefinition::for(OrderInput::class, 'Inner')
            ->validate(OrderValidation::class)
            ->node(self::INNER)->terminal()->build();
        $outerDefinition = ProcessDefinition::for('In', 'Outer')
            ->node(self::OUTER)->input('order')->terminal()->build();

        $outerOutcome = $runner->run($outerDefinition, new \stdClass(), null, $this->factory([
            self::OUTER => $this->subProcess(
                $runner,
                $inner,
                $this->factory([self::INNER => $this->writes('never')]),
                null,
                new OrderInput(null, [])
            ),
        ]));

        $this->assertSame('order.customer', $this->wire($outerOutcome, 'Outer')['fields'][0]['field']);
    }

    /**
     * Every failure form of a node's reject: the outer wire equals the inner wire - with and without
     * a key the inner node declared.
     *
     * @return array<string, array{ResponseStatus, string, array<string, mixed>, string}>
     */
    public static function rejectForms(): array
    {
        return [
            '400 validation, declared key' => [
                ResponseStatus::ValidationError,
                'raw.invalid',
                ['fields' => [['field' => 'qty', 'reason' => 'invalid', 'message' => 'bad', 'messageKey' => 'validation.invalid']]],
                'declared.invalid',
            ],
            '404 notFound' => [ResponseStatus::NotFound, 'raw.gone', ['sku' => 'Z-9'], ''],
            '404 notFound, declared key' => [ResponseStatus::NotFound, 'raw.gone', ['sku' => 'Z-9'], 'declared.gone'],
            '409 concurrencyConflict' => [
                ResponseStatus::Conflict,
                'raw.conflict',
                ['reason' => 'stale', 'aggregate' => 'Order', 'violated' => ['uq_order_no'], 'orderNo' => 'A-1'],
                '',
            ],
            '409 concurrencyConflict, declared key' => [
                ResponseStatus::Conflict,
                'raw.conflict',
                ['reason' => 'stale', 'aggregate' => 'Order', 'violated' => ['uq_order_no'], 'orderNo' => 'A-1'],
                'declared.conflict',
            ],
            '422 ruleViolation, own key' => [ResponseStatus::RuleViolation, 'order.noStock', ['rule' => 'InStock', 'sku' => 'A'], ''],
            '422 ruleViolation, declared key' => [
                ResponseStatus::RuleViolation,
                'order.noStock',
                ['rule' => 'InStock', 'sku' => 'A'],
                'declared.noStock',
            ],
            '403 no form, declared key' => [ResponseStatus::Forbidden, 'raw.forbidden', ['why' => 'owner'], 'declared.forbidden'],
        ];
    }

    /**
     * @param array<string, mixed> $context
     */
    #[DataProvider('rejectForms')]
    public function testARejectPassesOnWithTheSameStatusAndTheSameWireForm(
        ResponseStatus $status,
        string $key,
        array $context,
        string $declaredKey
    ): void {
        [$inner, $outer] = $this->runBoth(
            $this->innerDefinition($declaredKey === '' ? null : $status, $declaredKey),
            [self::INNER => $this->rejecting($status, $key, $context)]
        );

        $this->assertSame($status, $inner->status());
        $this->assertSame($status, $outer->status(), 'the outer answer has the status of the inner one');
        $this->assertSame($this->wire($inner, 'Inner'), $this->wire($outer, 'Outer'));
    }

    public function testAnInner422KeepsItsRuleAndKeyAndTheDeclaredKeyReachesTheWire(): void
    {
        [, $outer] = $this->runBoth(
            $this->innerDefinition(ResponseStatus::RuleViolation, 'declared.noStock'),
            [self::INNER => $this->rejecting(ResponseStatus::RuleViolation, 'order.noStock', ['rule' => 'InStock'])]
        );

        $wire = $this->wire($outer, 'Outer');
        $this->assertSame('ruleViolation', $wire['@type']);
        $this->assertSame('InStock', $wire['rule']);
        $this->assertSame('declared.noStock', $wire['messageKey']);
    }

    public function testAnInner409KeepsReasonAggregateAndViolated(): void
    {
        [, $outer] = $this->runBoth($this->innerDefinition(), [
            self::INNER => $this->rejecting(
                ResponseStatus::Conflict,
                'raw.conflict',
                ['reason' => 'stale', 'aggregate' => 'Order', 'violated' => ['uq_no']]
            ),
        ]);

        $wire = $this->wire($outer, 'Outer');
        $this->assertSame('concurrencyConflict', $wire['@type']);
        $this->assertSame(['stale', 'Order', ['uq_no']], [$wire['reason'], $wire['aggregate'], $wire['violated']]);
        $this->assertSame('raw.conflict', $wire['messageKey'], 'the key the inner node named with its reject travels');
    }

    public function testAnInner404KeepsItsContext(): void
    {
        [, $outer] = $this->runBoth($this->innerDefinition(), [
            self::INNER => $this->rejecting(ResponseStatus::NotFound, 'raw.gone', ['sku' => 'Z-9']),
        ]);

        $wire = $this->wire($outer, 'Outer');
        $this->assertSame('notFound', $wire['@type']);
        $this->assertSame(['sku' => 'Z-9'], $wire['context']);
    }

    public function testALayerKeyOfTheInnerRunNeverReachesTheOuterWire(): void
    {
        $runner = $this->runnerOn();
        $inner = ProcessDefinition::for(OrderInput::class, 'Inner')
            ->validate(OrderValidation::class)
            ->node(self::INNER)->terminal()->build();
        $outerDefinition = ProcessDefinition::for('In', 'Outer')->node(self::OUTER)->terminal()->build();

        $outer = $runner->run($outerDefinition, new \stdClass(), null, $this->factory([
            self::OUTER => $this->subProcess(
                $runner,
                $inner,
                $this->factory([self::INNER => $this->writes('never')]),
                null,
                new OrderInput(null, [])
            ),
        ]));

        $this->assertArrayNotHasKey('messageKey', $this->wire($outer, 'Outer'), 'process.inputInvalid stays internal');
    }

    public function testOnRejectRoutesAn4xxInsteadOfRefusingAndRollsBackTheInnerWrites(): void
    {
        $runner = $this->runnerOn();
        $outerDefinition = ProcessDefinition::for('In', 'Outer')
            ->node(self::OUTER)->onTrue('Outer\\Done')->onFalse('Outer\\Done')
            ->node('Outer\\Done')->terminal()
            ->build();

        $outcome = $runner->run($outerDefinition, new \stdClass(), null, $this->factory([
            self::OUTER => $this->subProcess(
                $runner,
                $this->innerDefinition(),
                $this->factory([self::INNER => $this->writesThenRejects('inner', ResponseStatus::RuleViolation, 'p.rule')]),
                Route::OnFalse
            ),
            'Outer\\Done' => $this->writes('after'),
        ]));

        $this->assertSame(ResponseStatus::Success, $outcome->status());
        $this->assertSame(['after'], $this->ledger->notes(), 'the inner row is rolled back, the outer run carries on');
    }

    public function testA5xxThrowsTheCauseOfTheInnerRun(): void
    {
        $boom = new \RuntimeException('boom');
        $runner = $this->runnerOn();
        $outerDefinition = ProcessDefinition::for('In', 'Outer')->node(self::OUTER)->terminal()->build();

        $outcome = $runner->run($outerDefinition, new \stdClass(), null, $this->factory([
            self::OUTER => $this->subProcess(
                $runner,
                $this->innerDefinition(),
                $this->factory([self::INNER => static function (ProcessContext $c) use ($boom): NodeResult {
                    throw $boom;
                }])
            ),
        ]));

        $this->assertSame(ResponseStatus::InternalError, $outcome->status());
        $this->assertSame($boom, $outcome->cause(), 'the very cause of the inner run, not a copy');
    }

    public function testA5xxWithoutACauseThrowsARuntimeExceptionNamingProcessAndStatus(): void
    {
        $outcome = new ProcessOutcome(ResponseStatus::InternalError, new \stdClass());

        try {
            (new MapOutcome())($outcome, null, 'Inner');
            $this->fail('a 5xx must throw');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Inner', $e->getMessage());
            $this->assertStringContainsString('500', $e->getMessage());
        }
    }

    public function testA5xxIsNeverRoutedAwayByOnReject(): void
    {
        $this->expectException(\RuntimeException::class);

        (new MapOutcome())(new ProcessOutcome(ResponseStatus::InternalError, new \stdClass()), Route::OnFalse);
    }

    /**
     * A node that rejects with the given status, key and context (no ledger write).
     *
     * @param array<string, mixed> $context
     * @return callable(ProcessContext): NodeResult
     */
    private function rejecting(ResponseStatus $status, string $key, array $context): callable
    {
        return static fn(ProcessContext $c): NodeResult => NodeResult::reject($status, $key, $context);
    }
}
