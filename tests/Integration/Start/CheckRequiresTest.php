<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Tests\Integration\Start;

use JardisSupport\Contract\Auth\AuthServicesInterface;
use JardisSupport\Contract\Kernel\DomainKernelInterface;
use JardisSupport\Workflow\Exception\ConfigurationException;
use JardisSupport\Workflow\Handler\Start\CheckRequires;
use PHPUnit\Framework\TestCase;
use Psr\SimpleCache\CacheInterface;

/**
 * The required resources of a process are checked against the kernel accessors (W7, A-N7).
 */
class CheckRequiresTest extends TestCase
{
    public function testMissingResourceIsAConfigurationException(): void
    {
        $kernel = $this->createStub(DomainKernelInterface::class);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('"auth"');

        (new CheckRequires())($kernel, ['auth']);
    }

    public function testProvidedResourcesPass(): void
    {
        $kernel = $this->createStub(DomainKernelInterface::class);
        $kernel->method('auth')->willReturn($this->createStub(AuthServicesInterface::class));
        $kernel->method('cache')->willReturn($this->createStub(CacheInterface::class));

        (new CheckRequires())($kernel, ['auth', 'cache']);

        $this->addToAssertionCount(1);
    }

    public function testOneMissingAmongProvidedFails(): void
    {
        $kernel = $this->createStub(DomainKernelInterface::class);
        $kernel->method('auth')->willReturn($this->createStub(AuthServicesInterface::class));

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('"messaging"');

        (new CheckRequires())($kernel, ['auth', 'messaging']);
    }

    public function testNothingRequiredPasses(): void
    {
        (new CheckRequires())($this->createStub(DomainKernelInterface::class), []);

        $this->addToAssertionCount(1);
    }

    public function testNameThatIsNoAccessorIsAConfigurationException(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('"teleporter"');

        (new CheckRequires())($this->createStub(DomainKernelInterface::class), ['teleporter']);
    }
}
