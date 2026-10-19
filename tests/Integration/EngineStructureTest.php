<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * The package is the engine and nothing else: exactly the documented classes (workflow 4.0), each of them loadable.
 */
class EngineStructureTest extends TestCase
{
    /** @var list<string> the classes of the package without the namespace prefix */
    private const DOCUMENTED = [
        'Data\\Builder\\WorkflowConfigBuilder',
        'Data\\NodeResult',
        'Data\\ProcessContext',
        'Data\\ProcessDefinition',
        'Data\\ProcessOutcome',
        'Data\\Route',
        'Data\\RuleMode',
        'Data\\RunRequest',
        'Data\\WorkflowConfig',
        'Exception\\ConstructionException',
        'Exception\\LoopLimitExceededException',
        'Exception\\UnroutedStatusException',
        'Exception\\WorkflowException',
        'Handler\\Context\\CreateContext',
        'Handler\\Context\\HydrateResponse',
        'Handler\\Events\\CollectEvents',
        'Handler\\Loop\\GuardLoop',
        'Handler\\Node\\ApplyRuleMode',
        'Handler\\Node\\MapResponse',
        'Handler\\Node\\ResolveNode',
        'Handler\\Outcome\\BuildOutcome',
        'Handler\\Outcome\\ExecuteProcess',
        'Handler\\Routing\\Workflow',
        'ProcessRunner',
    ];

    public function testPackageHoldsExactlyTheDocumentedClasses(): void
    {
        $root = dirname(__DIR__, 2) . '/src/';
        $found = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->getExtension() === 'php') {
                $found[] = str_replace('/', '\\', substr($file->getPathname(), strlen($root), -4));
            }
        }
        sort($found);
        $documented = self::DOCUMENTED;
        sort($documented);

        $this->assertSame($documented, $found);
        $this->assertCount(24, $found);
        foreach (self::DOCUMENTED as $class) {
            $reflection = new \ReflectionClass('JardisSupport\\Workflow\\' . $class);
            $this->assertSame(
                realpath($root . str_replace('\\', '/', $class) . '.php'),
                $reflection->getFileName(),
                $class
            );
        }
    }

    public function testEveryDocumentedClassLoadsWithoutAnotherPackage(): void
    {
        foreach (self::DOCUMENTED as $class) {
            $name = 'JardisSupport\\Workflow\\' . $class;
            $this->assertTrue(
                class_exists($name) || enum_exists($name) || interface_exists($name),
                $name
            );
        }
    }
}
