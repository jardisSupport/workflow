<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Tests\Integration\Data;

use JardisSupport\Contract\Kernel\ResponseStatus;
use JardisSupport\Workflow\Data\ProcessDefinition;
use JardisSupport\Workflow\Data\Route;
use JardisSupport\Workflow\Data\RuleMode;
use JardisSupport\Workflow\Exception\ConstructionException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * ProcessDefinition: one test per declaration method, graph validation, freeze at build().
 */
class ProcessDefinitionTest extends TestCase
{
    private const A = 'Node\\A';
    private const B = 'Node\\B';
    private const C = 'Node\\C';

    private function minimal(): ProcessDefinition
    {
        return ProcessDefinition::for('Input\\Dto', 'DoSomething')->node(self::A)->terminal();
    }

    public function testForSetsInputClassAndName(): void
    {
        $definition = ProcessDefinition::for('Input\\Dto', 'DoSomething');

        $this->assertSame('Input\\Dto', $definition->getInputClass());
        $this->assertSame('DoSomething', $definition->getName());
        $this->assertFalse($definition->isBuilt());
    }

    public function testForRejectsEmptyInputClassOrName(): void
    {
        $this->expectException(ConstructionException::class);

        ProcessDefinition::for('', 'DoSomething');
    }

    public function testForRejectsEmptyName(): void
    {
        $this->expectException(ConstructionException::class);

        ProcessDefinition::for('Input\\Dto', '');
    }

    public function testDefaultsAreTransactionalLoopLimit100AndNothingElse(): void
    {
        $definition = ProcessDefinition::for('Input\\Dto', 'P');

        $this->assertTrue($definition->isTransactional());
        $this->assertSame(100, $definition->getLoopLimit());
        $this->assertNull($definition->getContextClass());
        $this->assertNull($definition->getValidatorClass());
        $this->assertNull($definition->getActorClass());
        $this->assertSame([], $definition->getRequires());
    }

    public function testContextSetsTheContextClass(): void
    {
        $definition = ProcessDefinition::for('Input\\Dto', 'P')->context('Ctx\\PContext');

        $this->assertSame('Ctx\\PContext', $definition->getContextClass());
    }

    public function testTransactionalCanBeSwitchedOff(): void
    {
        $definition = ProcessDefinition::for('Input\\Dto', 'P')->transactional(false);

        $this->assertFalse($definition->isTransactional());
    }

    public function testValidateSetsTheValidatorClass(): void
    {
        $definition = ProcessDefinition::for('Input\\Dto', 'P')->validate('Reg\\Validators');

        $this->assertSame('Reg\\Validators', $definition->getValidatorClass());
    }

    public function testActorSetsTheActorClass(): void
    {
        $definition = ProcessDefinition::for('Input\\Dto', 'P')->actor('Auth\\Subject');

        $this->assertSame('Auth\\Subject', $definition->getActorClass());
    }

    public function testRequiresCollectsNamesAcrossCallsWithoutDuplicates(): void
    {
        $definition = ProcessDefinition::for('Input\\Dto', 'P')
            ->requires('mailer', 'cache')
            ->requires('cache', 'messaging');

        $this->assertSame(['mailer', 'cache', 'messaging'], $definition->getRequires());
    }

    public function testLoopLimitSetsTheLimit(): void
    {
        $definition = ProcessDefinition::for('Input\\Dto', 'P')->loopLimit(5);

        $this->assertSame(5, $definition->getLoopLimit());
    }

    public function testLoopLimitBelowOneFailsConstruction(): void
    {
        $this->expectException(ConstructionException::class);

        ProcessDefinition::for('Input\\Dto', 'P')->loopLimit(0);
    }

    public function testRuleModeIsStoredPerNode(): void
    {
        $definition = $this->minimal()->ruleMode(self::A, RuleMode::Decision);

        $this->assertSame(RuleMode::Decision, $definition->getRuleMode(self::A));
        $this->assertNull($definition->getRuleMode(self::B));
    }

    public function testRuleModeOnUnknownNodeFailsAtBuild(): void
    {
        $definition = $this->minimal()->ruleMode(self::B, RuleMode::Guard);

        $this->expectException(ConstructionException::class);

        $definition->build();
    }

    public function testNodeDeclaresNodesInOrderFirstIsEntry(): void
    {
        $definition = ProcessDefinition::for('Input\\Dto', 'P')
            ->node(self::A)->onTrue(self::B)
            ->node(self::B)->terminal()
            ->build();

        $this->assertSame([self::A, self::B], $definition->config()->nodes());
        $this->assertSame(self::A, $definition->config()->entry());
    }

    public function testNodeRejectsDuplicateAndEmptyNames(): void
    {
        $definition = ProcessDefinition::for('Input\\Dto', 'P')->node(self::A);

        try {
            $definition->node(self::A);
            $this->fail('duplicate node accepted');
        } catch (ConstructionException) {
            $this->addToAssertionCount(1);
        }

        $this->expectException(ConstructionException::class);
        $definition->node('');
    }

    #[DataProvider('routeSetters')]
    public function testRouteSettersMapTheRouteOfTheCurrentNode(string $method, Route $route): void
    {
        $definition = ProcessDefinition::for('Input\\Dto', 'P')->node(self::A);
        $definition->$method(self::B);
        $definition->node(self::B)->terminal();
        $definition->build();

        $this->assertSame([$route->value => self::B], $definition->config()->transitions(self::A));
    }

    /**
     * @return array<string, array{string, Route}>
     */
    public static function routeSetters(): array
    {
        return [
            'onTrue' => ['onTrue', Route::OnTrue],
            'onFalse' => ['onFalse', Route::OnFalse],
            'onSkip' => ['onSkip', Route::OnSkip],
            'onTimeout' => ['onTimeout', Route::OnTimeout],
            'onCancel' => ['onCancel', Route::OnCancel],
            'onExit' => ['onExit', Route::OnExit],
        ];
    }

    #[DataProvider('routeSetters')]
    public function testRouteSetterWithNullDeclaresATerminalEnd(string $method, Route $route): void
    {
        $definition = ProcessDefinition::for('Input\\Dto', 'P')->node(self::A);
        $definition->$method(null);
        $definition->build();

        $this->assertSame([$route->value => null], $definition->config()->transitions(self::A));
    }

    public function testRouteToAnUnknownTargetFailsAtBuild(): void
    {
        $definition = ProcessDefinition::for('Input\\Dto', 'P')->node(self::A)->onTrue(self::C);

        $this->expectException(ConstructionException::class);
        $this->expectExceptionMessage(self::C);

        $definition->build();
    }

    public function testForwardTargetsAreResolvedAtBuild(): void
    {
        $definition = ProcessDefinition::for('Input\\Dto', 'P')
            ->node(self::A)->onFalse(self::B)->terminal()
            ->node(self::B)->terminal()
            ->build();

        $this->assertSame(self::B, $definition->config()->transitions(self::A)['onFalse']);
    }

    public function testTerminalWithoutArgumentEndsOnTrue(): void
    {
        $definition = $this->minimal()->build();

        $this->assertSame(['onTrue' => null], $definition->config()->transitions(self::A));
    }

    public function testTerminalWithRoutesEndsExactlyThoseRoutes(): void
    {
        $definition = ProcessDefinition::for('Input\\Dto', 'P')
            ->node(self::A)->terminal(Route::OnTrue, Route::OnExit)
            ->build();

        $this->assertSame(['onTrue' => null, 'onExit' => null], $definition->config()->transitions(self::A));
    }

    public function testRejectsRecordsDeclaredPairsPerNode(): void
    {
        $definition = $this->minimal()
            ->rejects(ResponseStatus::NotFound, 'thing.notFound')
            ->rejects(ResponseStatus::Conflict, 'thing.conflict');

        $this->assertSame(
            [
                ['status' => ResponseStatus::NotFound, 'messageKey' => 'thing.notFound'],
                ['status' => ResponseStatus::Conflict, 'messageKey' => 'thing.conflict'],
            ],
            $definition->getRejects(self::A)
        );
        $this->assertSame([], $definition->getRejects(self::B));
    }

    public function testRejectsWithNon4xxStatusFailsConstruction(): void
    {
        $this->expectException(ConstructionException::class);

        $this->minimal()->rejects(ResponseStatus::InternalError, 'x');
    }

    public function testResponseRecordsTheFieldSubsetPerNode(): void
    {
        $definition = $this->minimal()->response(\stdClass::class, 'id', 'email');

        $this->assertSame(['id', 'email'], $definition->getResponse(self::A));
        $this->assertSame(\stdClass::class, $definition->getResponseClass(self::A));
        $this->assertNull($definition->getResponse(self::B));
        $this->assertNull($definition->getResponseClass(self::B));
    }

    public function testResponseWithoutFieldsMeansAllFieldsAndAnEmptyClassFails(): void
    {
        $definition = $this->minimal()->response(\stdClass::class);

        $this->assertSame([], $definition->getResponse(self::A));

        $this->expectException(ConstructionException::class);
        $this->minimal()->response('');
    }

    public function testNodeScopedDeclarationsBeforeAnyNodeFailConstruction(): void
    {
        $definition = ProcessDefinition::for('Input\\Dto', 'P');

        foreach ([
            static fn () => $definition->onTrue(null),
            static fn () => $definition->terminal(),
            static fn () => $definition->rejects(ResponseStatus::NotFound, 'k'),
            static fn () => $definition->response(\stdClass::class, 'id'),
        ] as $call) {
            try {
                $call();
                $this->fail('node-scoped call without node accepted');
            } catch (ConstructionException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testBuildOfAnEmptyGraphFailsConstruction(): void
    {
        $this->expectException(ConstructionException::class);

        ProcessDefinition::for('Input\\Dto', 'P')->build();
    }

    public function testBuildFreezesAndIsIdempotent(): void
    {
        $definition = $this->minimal();

        $built = $definition->build();

        $this->assertSame($definition, $built);
        $this->assertTrue($definition->isBuilt());
        $this->assertSame($definition, $definition->build());
    }

    public function testConfigBeforeBuildFailsConstruction(): void
    {
        $this->expectException(ConstructionException::class);

        $this->minimal()->config();
    }

    #[DataProvider('mutators')]
    public function testEveryMutationAfterBuildThrows(\Closure $mutation): void
    {
        $definition = $this->minimal()->build();

        $this->expectException(ConstructionException::class);

        $mutation($definition);
    }

    /**
     * @return array<string, array{\Closure}>
     */
    public static function mutators(): array
    {
        return [
            'context' => [static fn (ProcessDefinition $d) => $d->context('X')],
            'transactional' => [static fn (ProcessDefinition $d) => $d->transactional(false)],
            'validate' => [static fn (ProcessDefinition $d) => $d->validate('X')],
            'actor' => [static fn (ProcessDefinition $d) => $d->actor('X')],
            'requires' => [static fn (ProcessDefinition $d) => $d->requires('x')],
            'loopLimit' => [static fn (ProcessDefinition $d) => $d->loopLimit(3)],
            'ruleMode' => [static fn (ProcessDefinition $d) => $d->ruleMode(self::A, RuleMode::Guard)],
            'node' => [static fn (ProcessDefinition $d) => $d->node(self::B)],
            'onTrue' => [static fn (ProcessDefinition $d) => $d->onTrue(null)],
            'onFalse' => [static fn (ProcessDefinition $d) => $d->onFalse(null)],
            'onSkip' => [static fn (ProcessDefinition $d) => $d->onSkip(null)],
            'onTimeout' => [static fn (ProcessDefinition $d) => $d->onTimeout(null)],
            'onCancel' => [static fn (ProcessDefinition $d) => $d->onCancel(null)],
            'onExit' => [static fn (ProcessDefinition $d) => $d->onExit(null)],
            'terminal' => [static fn (ProcessDefinition $d) => $d->terminal()],
            'rejects' => [static fn (ProcessDefinition $d) => $d->rejects(ResponseStatus::NotFound, 'k')],
            'response' => [static fn (ProcessDefinition $d) => $d->response(\stdClass::class, 'id')],
        ];
    }
}
