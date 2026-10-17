<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Tests\Smoke\Support;

use JardisSupport\Contract\Kernel\ContextResponseInterface;
use JardisSupport\Contract\Kernel\DomainKernelInterface;
use JardisSupport\Contract\Kernel\GeneratedContextInterface;
use JardisSupport\Workflow\Data\Response\ContextResponse;

/**
 * Stub of the generated `{Domain}Context` base class (builder: renderer/php/domain.go).
 *
 * Same constructor and the same protected surface the generated handlers use: `handle()`, `resource()`,
 * `payload()`, `version()`, `result()`. Differences: `handle()` does not resolve a class through the
 * kernel container but calls the seam registered on the {@see FakeKernel}; `result()` builds the package's
 * own {@see ContextResponse}, the generat takes the kernel package's class of the same contract.
 */
abstract class StubDomainContext implements GeneratedContextInterface
{
    private ?ContextResponseInterface $result = null;

    public function __construct(
        private readonly DomainKernelInterface $domainKernel,
        private readonly mixed $payload = null,
        private readonly string $version = ''
    ) {
    }

    /**
     * @param class-string $className
     */
    protected function handle(string $className, mixed ...$parameters): mixed
    {
        $kernel = $this->domainKernel;

        return $kernel instanceof FakeKernel
            ? $kernel->handle($className, ...$parameters)
            : throw new \LogicException('The stub context needs the FakeKernel');
    }

    protected function resource(): DomainKernelInterface
    {
        return $this->domainKernel;
    }

    protected function payload(): mixed
    {
        return $this->payload;
    }

    protected function version(): string
    {
        return $this->version;
    }

    protected function result(): ContextResponseInterface
    {
        if ($this->result === null) {
            $this->result = new ContextResponse(basename(str_replace('\\', '/', static::class)));
        }

        return $this->result;
    }
}
