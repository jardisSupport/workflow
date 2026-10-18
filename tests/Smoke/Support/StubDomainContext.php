<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Tests\Smoke\Support;

use JardisSupport\Contract\Kernel\ContextResponseInterface;
use JardisSupport\Contract\Kernel\DomainKernelInterface;
use JardisSupport\Contract\Kernel\GeneratedContextInterface;
use JardisSupport\Scheduling\Clock\FixedClock;
use JardisSupport\Workflow\Data\Response\ContextResponse;
use JardisSupport\Workflow\Handler\Response\TransformResponse;
use Psr\Clock\ClockInterface;

/**
 * Stub of the generated `{Domain}Context` base class (builder: renderer/php/domain.go).
 *
 * Same constructor and the same protected surface the generated handlers use: `handle()`, `resource()`,
 * `payload()`, `version()`, `actor()`, `result()`, `transformer()`, `clock()`. Differences: `handle()`
 * does not resolve a class through the kernel container (a generated context is built with the run's
 * kernel, payload and version as the generat does; any other class is a facade registered on the
 * {@see FakeKernel} or a plain object); `clock()` is a frozen clock, so the envelope bytes are
 * reproducible (the generat resolves the system clock); `result()` builds the package's own
 * {@see ContextResponse}, the generat takes the kernel package's class of the same contract.
 */
abstract class StubDomainContext implements GeneratedContextInterface
{
    /** The frozen instant of the stub clock (`meta.timestamp` of the wire contract). */
    public const FIXED_AT = '2026-01-15T10:30:00+00:00';

    private ?ContextResponseInterface $result = null;

    public function __construct(
        private readonly DomainKernelInterface $domainKernel,
        private readonly mixed $payload = null,
        private readonly string $version = '',
        private readonly ?object $actor = null
    ) {
    }

    /**
     * @param class-string $className
     */
    protected function handle(string $className, mixed ...$parameters): mixed
    {
        $kernel = $this->domainKernel;
        if (!$kernel instanceof FakeKernel) {
            throw new \LogicException('The stub context needs the FakeKernel');
        }
        $facade = $kernel->facade($className);
        if ($facade !== null) {
            return $facade;
        }

        return is_subclass_of($className, GeneratedContextInterface::class)
            ? new $className($kernel, $this->payload, $this->version, ...$parameters)
            : new $className(...$parameters);
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

    protected function actor(): ?object
    {
        return $this->actor;
    }

    protected function clock(): ClockInterface
    {
        return new FixedClock(new \DateTimeImmutable(self::FIXED_AT));
    }

    protected function transformer(): TransformResponse
    {
        return new TransformResponse($this->clock(), $this->version);
    }

    protected function result(): ContextResponseInterface
    {
        if ($this->result === null) {
            $this->result = new ContextResponse(basename(str_replace('\\', '/', static::class)));
        }

        return $this->result;
    }
}
