<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Tests\Integration\Failure;

use JardisSupport\Contract\Kernel\ResponseStatus;
use JardisSupport\Workflow\Data\NodeResult;
use JardisSupport\Workflow\Data\ProcessContext;
use JardisSupport\Workflow\Data\ProcessDefinition;
use JardisSupport\Workflow\Data\RuleMode;
use JardisSupport\Workflow\Tests\Support\TransactionTestCase;

/**
 * ruleMode applies the declared mode: a decision routes a violation to OnFalse, a guard rejects (W2).
 */
class ApplyRuleModeTest extends TransactionTestCase
{
    private const RULE = 'Node\\Rule';
    private const YES = 'Node\\Yes';
    private const NO = 'Node\\No';

    private function definition(?RuleMode $mode): ProcessDefinition
    {
        $definition = ProcessDefinition::for('In', 'P');
        if ($mode !== null) {
            $definition->ruleMode(self::RULE, $mode);
        }

        return $definition
            ->node(self::RULE)->onTrue(self::YES)->onFalse(self::NO)
            ->node(self::YES)->terminal()
            ->node(self::NO)->terminal()
            ->build();
    }

    /**
     * @return array<string, callable(ProcessContext): NodeResult>
     */
    private function nodes(ResponseStatus $ruleStatus): array
    {
        return [
            self::RULE => static fn(ProcessContext $c): NodeResult => NodeResult::reject($ruleStatus, 'p.rule'),
            self::YES => static fn(ProcessContext $c): NodeResult => NodeResult::ok(['via' => 'yes']),
            self::NO => static fn(ProcessContext $c): NodeResult => NodeResult::ok(['via' => 'no']),
        ];
    }

    public function testDecisionRoutesARuleViolationToOnFalse(): void
    {
        $built = [];

        $outcome = $this->runnerOn()->run(
            $this->definition(RuleMode::Decision),
            new \stdClass(),
            null,
            $this->factory($this->nodes(ResponseStatus::RuleViolation), $built)
        );

        $this->assertSame(ResponseStatus::Success, $outcome->status());
        $this->assertSame([self::RULE, self::NO], $built);
        $this->assertEquals((object) ['via' => 'no'], $outcome->response());
    }

    public function testGuardKeepsTheReject(): void
    {
        $built = [];

        $outcome = $this->runnerOn()->run(
            $this->definition(RuleMode::Guard),
            new \stdClass(),
            null,
            $this->factory($this->nodes(ResponseStatus::RuleViolation), $built)
        );

        $this->assertSame(ResponseStatus::RuleViolation, $outcome->status());
        $this->assertSame([self::RULE], $built);
    }

    public function testNodeWithoutADeclaredModeIsAGuard(): void
    {
        $outcome = $this->runnerOn()->run(
            $this->definition(null),
            new \stdClass(),
            null,
            $this->factory($this->nodes(ResponseStatus::RuleViolation))
        );

        $this->assertSame(ResponseStatus::RuleViolation, $outcome->status());
    }

    public function testDecisionOnlyTurnsRuleViolationsNotOtherRejects(): void
    {
        $outcome = $this->runnerOn()->run(
            $this->definition(RuleMode::Decision),
            new \stdClass(),
            null,
            $this->factory($this->nodes(ResponseStatus::NotFound))
        );

        $this->assertSame(ResponseStatus::NotFound, $outcome->status());
    }
}
