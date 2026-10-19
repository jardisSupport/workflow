<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Tests\Integration\Runner;

use JardisSupport\Workflow\ProcessRunner;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * The runner is the bare engine: it takes no port - no connection, no validator, no logger - and so
 * has no constructor (4.0, Posten 16).
 */
class ProcessRunnerStructureTest extends TestCase
{
    public function testRunnerHasNoConstructor(): void
    {
        $this->assertNull((new ReflectionClass(ProcessRunner::class))->getConstructor());
    }

    public function testRunnerHasNoStaticFactoryAndOneRunMethod(): void
    {
        $methods = array_map(
            static fn(\ReflectionMethod $method): string => $method->getName(),
            (new ReflectionClass(ProcessRunner::class))->getMethods(\ReflectionMethod::IS_PUBLIC)
        );

        $this->assertSame(['run'], $methods);
    }

    public function testRunTakesTheDepthOfTheRunAsItsOptionalLastParameter(): void
    {
        $parameters = (new \ReflectionMethod(ProcessRunner::class, 'run'))->getParameters();
        $depth = end($parameters);

        $this->assertNotFalse($depth);
        $this->assertSame('depth', $depth->getName());
        $this->assertSame('int', (string) $depth->getType());
        $this->assertTrue($depth->isOptional());
        $this->assertSame(0, $depth->getDefaultValue());
    }
}
