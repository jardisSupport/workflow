<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Tests\Integration\Runner;

use JardisSupport\Contract\Kernel\ResponseStatus;
use JardisSupport\Workflow\Data\Answer;
use JardisSupport\Workflow\Data\NodeResult;
use JardisSupport\Workflow\Data\ProcessContext;
use JardisSupport\Workflow\Data\ProcessDefinition;
use JardisSupport\Workflow\Data\RuleMode;
use JardisSupport\Workflow\Handler\Node\MapResponse;
use JardisSupport\Workflow\Tests\Support\FakeDomainResponse;
use JardisSupport\Workflow\Tests\Support\RunnerTestCase;

/**
 * Engine side of the "register unless it exists" flow: a decision node that finds nothing routes on onFalse, the
 * follower reads the shared state of the run and the merged answer carries what every step produced.
 */
class MergedAnswerFlowTest extends RunnerTestCase
{
    private const FIND = 'Ecommerce\\FindExistingCustomer';
    private const REGISTER = 'Ecommerce\\RegisterCustomer';
    private const WELCOME = 'Ecommerce\\SendWelcome';
    private const REUSE = 'Ecommerce\\ReuseCustomer';

    public function testEmptyDecisionRoutesOnFalseAndTheAnswerUnitesAllNodes(): void
    {
        $definition = ProcessDefinition::for('In', 'RegisterCustomer')
            ->answer(Answer::Merged)
            ->ruleMode(self::FIND, RuleMode::Decision)
            ->node(self::FIND)->onTrue(self::REUSE)->onFalse(self::REGISTER)
            ->node(self::REGISTER)->onTrue(self::WELCOME)
            ->node(self::REUSE)->terminal()
            ->node(self::WELCOME)->terminal()
            ->build();
        $built = [];
        $event = new \stdClass();

        $outcome = $this->runner()->run($definition, new \stdClass(), null, $this->factory([
            // the lookup finds nobody: the callee answers 422 without data, the decision turns it into onFalse
            self::FIND => static fn(ProcessContext $c): NodeResult => (new MapResponse())(
                new FakeDomainResponse(422, ['messageKey' => 'customer.none_found'])
            ),
            self::REGISTER => static fn(ProcessContext $c): NodeResult => (new MapResponse())(
                new FakeDomainResponse(201, ['customerNumber' => 'C-100', 'status' => 'registered'], [], [$event])
            ),
            self::WELCOME => static fn(ProcessContext $c): NodeResult => NodeResult::ok([
                'welcomeFor' => $c->latest(self::REGISTER)?->data()['customerNumber'] ?? null,
                'wasSearched' => $c->latest(self::FIND) !== null && $c->all(self::FIND) !== [],
                'status' => 'welcomed',
            ]),
            self::REUSE => static fn(ProcessContext $c): NodeResult => NodeResult::ok(['status' => 'reused']),
        ], $built));

        $this->assertSame([self::FIND, self::REGISTER, self::WELCOME], $built, 'onFalse leads to the registration');
        $this->assertSame(ResponseStatus::Success, $outcome->status());
        $this->assertSame(
            ['customerNumber' => 'C-100', 'status' => 'welcomed', 'welcomeFor' => 'C-100', 'wasSearched' => true],
            (array) $outcome->response(),
            'the empty decision adds no field, the shared state reaches the follower, the later status wins'
        );
        $this->assertSame([$event], $outcome->events());
    }
}
