<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Tests\Integration\Runner;

use JardisSupport\Workflow\ProcessRunner;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * Structure proofs on the real sources: the runner is pure composition (W1, A-V1), every handler
 * has one entry point (A-V2), the runner depends on interfaces only (A-V5) and the package holds
 * no persistence of its own, no suspension and no event sending (A-B1, A-N2).
 */
class RunnerStructureTest extends TestCase
{
    private const NO_LOGIC = '/\b(if|switch|match|while|for|foreach|try|catch)\b|\?\?|\?:/';

    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    /**
     * @return list<string> absolute paths of all php files below a source directory
     */
    private function phpFiles(string $directory): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory));
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        return $files;
    }

    /**
     * @return list<string> 'file:line' of every line of the source tree matching the pattern
     */
    private function grep(string $pattern): array
    {
        $hits = [];
        foreach ($this->phpFiles(self::root() . '/src') as $file) {
            foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $number => $line) {
                if (preg_match($pattern, $line) === 1) {
                    $hits[] = $file . ':' . ($number + 1);
                }
            }
        }

        return $hits;
    }

    public function testGrepPatternsReallyMatch(): void
    {
        $this->assertSame(1, preg_match(self::NO_LOGIC, '        while ($node !== null) {'));
        $this->assertSame(1, preg_match(self::NO_LOGIC, '$a = $b ?? $c;'));
        $this->assertSame(1, preg_match(self::NO_LOGIC, '$a = $b ?: $c;'));
        $this->assertGreaterThan(20, count($this->phpFiles(self::root() . '/src')));
    }

    public function testRunnerIsFinalAndHasNoLogic(): void
    {
        $file = self::root() . '/src/ProcessRunner.php';

        $this->assertFileExists($file);
        $this->assertTrue((new ReflectionClass(ProcessRunner::class))->isFinal());
        $lines = file($file, FILE_IGNORE_NEW_LINES);
        $this->assertNotFalse($lines);
        $this->assertGreaterThan(30, count($lines));
        $this->assertSame([], array_filter($lines, static fn(string $line): bool => preg_match(self::NO_LOGIC, $line) === 1));
    }

    public function testEveryHandlerHasExactlyOnePublicEntryPoint(): void
    {
        $files = $this->phpFiles(self::root() . '/src/Handler');
        $this->assertGreaterThanOrEqual(9, count($files));

        foreach ($files as $file) {
            $class = 'JardisSupport\\Workflow\\' . str_replace(
                '/',
                '\\',
                substr($file, strlen(self::root() . '/src/'), -4)
            );
            $public = array_map(
                static fn(ReflectionMethod $method): string => $method->getName(),
                array_filter(
                    (new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC),
                    static fn(ReflectionMethod $method): bool => !$method->isConstructor()
                )
            );

            $this->assertSame(['__invoke'], array_values($public), $class);
        }
    }

    public function testRunnerConstructorTakesInterfacesOnly(): void
    {
        $parameters = (new ReflectionClass(ProcessRunner::class))->getConstructor()?->getParameters() ?? [];

        $this->assertCount(2, $parameters);
        foreach ($parameters as $parameter) {
            $type = $parameter->getType();
            $this->assertInstanceOf(ReflectionNamedType::class, $type);
            $this->assertTrue(interface_exists($type->getName()), $parameter->getName());
        }
    }

    public function testPackageHoldsNoPersistenceNoSuspensionAndSendsNoEvents(): void
    {
        $this->assertSame([], $this->grep('/new ObjectValidator|PDO|ConnectionPool/'), 'no own persistence');
        $this->assertSame([], $this->grep('/resume|suspend|persistState|wait/i'), 'no suspension');
        $this->assertSame([], $this->grep('/publish|dispatch/'), 'events are never sent here');
    }
}
