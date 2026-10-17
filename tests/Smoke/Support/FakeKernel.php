<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Tests\Smoke\Support;

use JardisSupport\Contract\Auth\AuthServicesInterface;
use JardisSupport\Contract\EventListener\EventListenerRegistryInterface;
use JardisSupport\Contract\Filesystem\FilesystemServiceInterface;
use JardisSupport\Contract\Kernel\DomainKernelInterface;
use JardisSupport\Contract\Kernel\DomainResponseInterface;
use JardisSupport\Contract\Mailer\MailerInterface;
use JardisSupport\Contract\Messaging\MessagingServiceInterface;
use PDO;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * Fake kernel of the smoke: a SQLite PDO as database, nothing else.
 *
 * Also holds the seam the stub context's `handle()` calls: the test registers what the "other side"
 * of a call node answers.
 */
final class FakeKernel implements DomainKernelInterface
{
    /** @var \Closure(string, mixed...): DomainResponseInterface|null */
    private ?\Closure $seam = null;

    private readonly PDO $pdo;

    public function __construct()
    {
        $this->pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
    }

    /**
     * @param \Closure(string, mixed...): DomainResponseInterface $seam
     */
    public function onHandle(\Closure $seam): void
    {
        $this->seam = $seam;
    }

    public function handle(string $className, mixed ...$parameters): DomainResponseInterface
    {
        return ($this->seam ?? throw new \LogicException('no handle() seam registered'))($className, ...$parameters);
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public function projectRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    public function env(string $key): mixed
    {
        return null;
    }

    public function container(): ContainerInterface
    {
        return new EmptyContainer();
    }

    public function cache(): ?CacheInterface
    {
        return null;
    }

    public function logger(): ?LoggerInterface
    {
        return null;
    }

    public function eventDispatcher(): ?EventDispatcherInterface
    {
        return null;
    }

    public function eventListenerRegistry(): ?EventListenerRegistryInterface
    {
        return null;
    }

    public function httpClient(): ?ClientInterface
    {
        return null;
    }

    public function dbConnection(): PDO
    {
        return $this->pdo;
    }

    public function mailer(): ?MailerInterface
    {
        return null;
    }

    public function filesystem(): ?FilesystemServiceInterface
    {
        return null;
    }

    public function messaging(): ?MessagingServiceInterface
    {
        return null;
    }

    public function auth(): ?AuthServicesInterface
    {
        return null;
    }
}
