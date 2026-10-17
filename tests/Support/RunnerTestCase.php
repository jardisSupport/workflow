<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Tests\Support;

use JardisSupport\Contract\DbConnection\DbConnectionInterface;
use JardisSupport\Contract\Validation\ValidatorInterface;
use JardisSupport\Workflow\Data\NodeResult;
use JardisSupport\Workflow\Data\ProcessContext;
use JardisSupport\Workflow\ProcessRunner;
use PHPUnit\Framework\TestCase;

/**
 * Shared set-up of the runner tests: a runner over stub ports and a node factory built from a map.
 */
abstract class RunnerTestCase extends TestCase
{
    protected function runner(): ProcessRunner
    {
        return new ProcessRunner(
            $this->createStub(DbConnectionInterface::class),
            $this->createStub(ValidatorInterface::class)
        );
    }

    /**
     * @param array<string, callable(ProcessContext): NodeResult> $nodes node class => node callable
     * @param list<string> $built classes in the order the runner asked for them
     * @return \Closure(class-string): (callable(ProcessContext): NodeResult)
     */
    protected function factory(array $nodes, array &$built = []): \Closure
    {
        return static function (string $class) use ($nodes, &$built): callable {
            $built[] = $class;

            return $nodes[$class] ?? throw new \LogicException('no node registered for ' . $class);
        };
    }
}
