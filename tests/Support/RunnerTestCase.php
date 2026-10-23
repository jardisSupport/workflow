<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Tests\Support;

use JardisSupport\Contract\Kernel\DomainResponseInterface;
use JardisSupport\Contract\Kernel\ResponseStatus;
use JardisSupport\Workflow\Data\NodeResult;
use JardisSupport\Workflow\Data\ProcessContext;
use JardisSupport\Workflow\Data\ProcessDefinition;
use JardisSupport\Workflow\Data\ProcessOutcome;
use JardisSupport\Workflow\Data\Route;
use JardisSupport\Workflow\Handler\Node\MapResponse;
use JardisSupport\Workflow\ProcessRunner;
use PHPUnit\Framework\TestCase;

/**
 * Shared set-up of the runner tests: the engine and a node factory built from a map.
 */
abstract class RunnerTestCase extends TestCase
{
    protected function runner(): ProcessRunner
    {
        return new ProcessRunner();
    }

    /**
     * @param array<string, callable(ProcessContext): NodeResult> $nodes node class => node callable
     * @param list<string> $built classes in the order the runner asked for them
     * @return \Closure(class-string): object
     */
    protected function factory(array $nodes, array &$built = []): \Closure
    {
        return static function (string $class) use ($nodes, &$built): object {
            $built[] = $class;

            return $nodes[$class] ?? throw new \LogicException('no node registered for ' . $class);
        };
    }

    /**
     * What the inner door of a sub-process answers for an outcome, in the data form of its status: a success
     * carries the response, a 400 its fields, a 404 its context, a 409 its own fields beside the context, a 422 its
     * rule and key beside the context, every other 4xx the plain context; the errors are keyed by context.
     */
    protected function doorAnswer(ProcessOutcome $outcome): DomainResponseInterface
    {
        $context = $outcome->context();
        $data = match ($outcome->status()) {
            ResponseStatus::Success => (array) $outcome->response(),
            ResponseStatus::ValidationError => ['@type' => 'validation'] + $context,
            ResponseStatus::NotFound => ['@type' => 'notFound', 'context' => $context],
            ResponseStatus::Conflict => ['@type' => 'concurrencyConflict']
                + array_intersect_key($context, ['reason' => true, 'aggregate' => true, 'violated' => true])
                + ['context' => array_diff_key($context, ['reason' => true, 'aggregate' => true, 'violated' => true])],
            ResponseStatus::RuleViolation => ['@type' => 'ruleViolation', 'rule' => $context['rule'] ?? '']
                + ['messageKey' => (string) $outcome->messageKey()]
                + ['context' => array_diff_key($context, ['rule' => true])],
            default => $context,
        };

        return new FakeDomainResponse(
            $outcome->status()->value,
            $data,
            $outcome->errors() === [] ? [] : ['' => $outcome->errors()],
            $outcome->events()
        );
    }

    /**
     * A sub-process node as the generated code writes it: it runs the inner process one level deeper and maps the
     * answer of the inner door with {@see MapResponse} - the events travel on, a 4xx is rejected again with the same
     * status, and `$onReject` (a dev decision) may route a 4xx instead.
     *
     * @param \Closure(class-string): object $innerNodes
     * @return callable(ProcessContext): NodeResult
     */
    protected function subProcess(
        ProcessRunner $runner,
        ProcessDefinition $inner,
        \Closure $innerNodes,
        ?Route $onReject = null
    ): callable {
        return function (ProcessContext $context) use ($runner, $inner, $innerNodes, $onReject): NodeResult {
            $outcome = $runner->run($inner, new \stdClass(), $innerNodes, $context->depth() + 1);

            return (new MapResponse())($this->doorAnswer($outcome), $onReject);
        };
    }
}
