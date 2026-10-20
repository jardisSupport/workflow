<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Tests\Integration\Runner;

use JardisSupport\Contract\Kernel\ResponseStatus;
use JardisSupport\Workflow\Data\Answer;
use JardisSupport\Workflow\Data\NodeResult;
use JardisSupport\Workflow\Data\ProcessContext;
use JardisSupport\Workflow\Data\ProcessDefinition;
use JardisSupport\Workflow\Data\Route;
use JardisSupport\Workflow\Exception\ConstructionException;
use JardisSupport\Workflow\Tests\Support\Process\Quote\QuoteAnswer;
use JardisSupport\Workflow\Tests\Support\RunnerTestCase;

/**
 * The typed answer of a whole process (Bescheid D8): with Answer::Merged and a declared process answer class the
 * united data of all steps is projected onto the fields of that class, whatever the last node is.
 */
class AnswerResponseTest extends RunnerTestCase
{
    private const CREATE = 'Quote\\Create';
    private const SUB = 'Quote\\PriceCheck';
    private const NOTIFY = 'Quote\\Notify';
    private const INNER = 'Quote\\Inner';

    private function create(): callable
    {
        return static fn(ProcessContext $c): NodeResult => NodeResult::ok([
            'quoteNumber' => 'Q-1',
            'status' => 'open',
            'internalId' => 99,
        ]);
    }

    public function testEndNodeIsASubProcessWithForeignAnswerAndTheProcessAnswersItsDeclaredFields(): void
    {
        $inner = ProcessDefinition::for('In', 'Inner')->node(self::INNER)->terminal()->build();
        $definition = ProcessDefinition::for('In', 'Quote')
            ->answer(Answer::Merged)
            ->answerResponse(QuoteAnswer::class, 'quoteNumber', 'status')
            ->node(self::CREATE)->onTrue(self::SUB)
            ->node(self::SUB)->response(QuoteAnswer::class)->terminal()
            ->build();
        $runner = $this->runner();
        $innerNodes = $this->factory([
            self::INNER => static fn(ProcessContext $c): NodeResult => NodeResult::ok(['foreign' => 1, 'status' => 'priced']),
        ]);

        $outcome = $runner->run($definition, new \stdClass(), null, $this->factory([
            self::CREATE => $this->create(),
            self::SUB => $this->subProcess($runner, $inner, $innerNodes),
        ]));

        $this->assertSame(ResponseStatus::Success, $outcome->status());
        $this->assertEquals(new QuoteAnswer('Q-1', 'priced'), $outcome->response());
    }

    public function testEndNodeWithoutDataAnswersTheFieldsOfEarlierSteps(): void
    {
        $definition = ProcessDefinition::for('In', 'Quote')
            ->answer(Answer::Merged)
            ->answerResponse(QuoteAnswer::class)
            ->node(self::CREATE)->onTrue(self::NOTIFY)
            ->node(self::NOTIFY)->terminal()
            ->build();
        $event = new \stdClass();

        $outcome = $this->runner()->run($definition, new \stdClass(), null, $this->factory([
            self::CREATE => $this->create(),
            self::NOTIFY => static fn(ProcessContext $c): NodeResult => NodeResult::route(Route::OnTrue)
                ->withEvents($event),
        ]));

        $this->assertEquals(new QuoteAnswer('Q-1', 'open'), $outcome->response());
        $this->assertSame([$event], $outcome->events());
    }

    public function testFieldsNotDeclaredFallAwayAndAnOptionalFieldIsFilledWhenDeclared(): void
    {
        $definition = ProcessDefinition::for('In', 'Quote')
            ->answer(Answer::Merged)
            ->answerResponse(QuoteAnswer::class, 'quoteNumber', 'status', 'note')
            ->node(self::CREATE)->terminal()
            ->build();

        $outcome = $this->runner()->run($definition, new \stdClass(), null, $this->factory([
            self::CREATE => static fn(ProcessContext $c): NodeResult => NodeResult::ok([
                'quoteNumber' => 'Q-1', 'status' => 'open', 'note' => 'rush', 'internalId' => 99,
            ]),
        ]));

        $this->assertEquals(new QuoteAnswer('Q-1', 'open', 'rush'), $outcome->response());
        $this->assertSame(['quoteNumber', 'status', 'note'], $definition->getAnswerResponse());
        $this->assertSame(QuoteAnswer::class, $definition->getAnswerResponseClass());
    }

    public function testMissingRequiredFieldThrowsAndNamesTheProcess(): void
    {
        $definition = ProcessDefinition::for('In', 'Quote')
            ->answer(Answer::Merged)
            ->answerResponse(QuoteAnswer::class)
            ->node(self::CREATE)->terminal()
            ->build();

        $this->expectException(ConstructionException::class);
        $this->expectExceptionMessage('needs a value for the required field "status" of process Quote');

        $this->runner()->run($definition, new \stdClass(), null, $this->factory([
            self::CREATE => static fn(ProcessContext $c): NodeResult => NodeResult::ok(['quoteNumber' => 'Q-1']),
        ]));
    }

    public function testUnknownAnswerClassThrowsAtRunTime(): void
    {
        $definition = ProcessDefinition::for('In', 'Quote')
            ->answer(Answer::Merged)
            ->answerResponse('Quote\\DoesNotExist')
            ->node(self::CREATE)->terminal()
            ->build();

        $this->expectException(ConstructionException::class);
        $this->expectExceptionMessage('Process Quote declares the response class Quote\\DoesNotExist');

        $this->runner()->run($definition, new \stdClass(), null, $this->factory([
            self::CREATE => $this->create(),
        ]));
    }

    public function testLastNodeWithAnswerClassIsRefusedAtBuildWhereverItIsDeclared(): void
    {
        foreach ([true, false] as $classFirst) {
            $definition = ProcessDefinition::for('In', 'Quote');
            if ($classFirst) {
                $definition->answerResponse(QuoteAnswer::class);
            }
            $definition->answer(Answer::LastNode);
            if (!$classFirst) {
                $definition->answerResponse(QuoteAnswer::class);
            }
            $definition->node(self::CREATE)->terminal();

            try {
                $definition->build();
                $this->fail('build() must refuse a process answer class without Answer::Merged');
            } catch (ConstructionException $e) {
                $this->assertStringContainsString('needs Answer::Merged, the answer is lastNode', $e->getMessage());
            }
        }
    }

    public function testEmptyAnswerClassAndFrozenDefinitionThrow(): void
    {
        $definition = ProcessDefinition::for('In', 'Quote')->answer(Answer::Merged);
        try {
            $definition->answerResponse('');
            $this->fail('an empty class must throw');
        } catch (ConstructionException $e) {
            $this->assertStringContainsString('needs a response class', $e->getMessage());
        }

        $built = $definition->node(self::CREATE)->terminal()->build();
        $this->expectException(ConstructionException::class);
        $built->answerResponse(QuoteAnswer::class);
    }

    public function testLastNodeAnswerIsUnchangedAndDeclaresNoClass(): void
    {
        $definition = ProcessDefinition::for('In', 'Quote')
            ->node(self::CREATE)->onTrue(self::NOTIFY)
            ->node(self::NOTIFY)->terminal()
            ->build();

        $outcome = $this->runner()->run($definition, new \stdClass(), null, $this->factory([
            self::CREATE => $this->create(),
            self::NOTIFY => static fn(ProcessContext $c): NodeResult => NodeResult::ok(['sent' => true]),
        ]));

        $this->assertEquals((object) ['sent' => true], $outcome->response());
        $this->assertNull($definition->getAnswerResponseClass());
        $this->assertNull($definition->getAnswerResponse());
    }

    public function testMergedWithoutAnswerClassStaysTheUnprojectedUnion(): void
    {
        $definition = ProcessDefinition::for('In', 'Quote')
            ->answer(Answer::Merged)
            ->node(self::CREATE)->onTrue(self::NOTIFY)
            ->node(self::NOTIFY)->terminal()
            ->build();

        $outcome = $this->runner()->run($definition, new \stdClass(), null, $this->factory([
            self::CREATE => $this->create(),
            self::NOTIFY => static fn(ProcessContext $c): NodeResult => NodeResult::ok(['sent' => true]),
        ]));

        $this->assertSame(
            ['quoteNumber' => 'Q-1', 'status' => 'open', 'internalId' => 99, 'sent' => true],
            (array) $outcome->response()
        );
    }

    public function testNodeResponseOfAnInnerNodeStillHydratesWhenTheProcessDeclaresAnAnswerClass(): void
    {
        $definition = ProcessDefinition::for('In', 'Quote')
            ->answer(Answer::Merged)
            ->answerResponse(QuoteAnswer::class, 'quoteNumber', 'status')
            ->node(self::CREATE)->response(QuoteAnswer::class)->onTrue(self::NOTIFY)
            ->node(self::NOTIFY)->terminal()
            ->build();
        $seen = null;

        $outcome = $this->runner()->run($definition, new \stdClass(), null, $this->factory([
            self::CREATE => $this->create(),
            self::NOTIFY => static function (ProcessContext $c) use (&$seen): NodeResult {
                $seen = $c->latest(self::CREATE)?->data();

                return NodeResult::route(Route::OnTrue);
            },
        ]));

        $this->assertEquals(new QuoteAnswer('Q-1', 'open'), $seen, 'a node that is not the end keeps its class');
        $this->assertEquals(new QuoteAnswer('Q-1', 'open'), $outcome->response());
    }

    public function testRejectIsUnaffectedByTheAnswerClass(): void
    {
        $definition = ProcessDefinition::for('In', 'Quote')
            ->answer(Answer::Merged)
            ->answerResponse(QuoteAnswer::class)
            ->node(self::CREATE)->rejects(ResponseStatus::NotFound, 'quote.notFound')->terminal()
            ->build();

        $outcome = $this->runner()->run($definition, new \stdClass(), null, $this->factory([
            self::CREATE => static fn(ProcessContext $c): NodeResult => NodeResult::reject(
                ResponseStatus::NotFound,
                'quote.notFound',
                ['quoteNumber' => 'Q-404']
            ),
        ]));

        $this->assertSame(ResponseStatus::NotFound, $outcome->status());
        $this->assertEquals(new \stdClass(), $outcome->response());
        $this->assertSame('quote.notFound', $outcome->messageKey());
    }
}
