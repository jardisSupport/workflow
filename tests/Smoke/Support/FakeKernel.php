<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Tests\Smoke\Support;

use JardisSupport\Contract\Auth\AuthServicesInterface;
use JardisSupport\Contract\EventListener\EventListenerRegistryInterface;
use JardisSupport\Contract\Filesystem\FilesystemServiceInterface;
use JardisSupport\Contract\Kernel\DomainKernelInterface;
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
 * Also holds the facades the stub context's `handle()` answers: the test registers what the "other side"
 * of a call node is.
 */
final class FakeKernel implements DomainKernelInterface
{
    /** @var array<class-string, object> */
    private array $facades = [];

    private readonly PDO $pdo;

    public function __construct()
    {
        $this->pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
    }

    /**
     * Registers what `handle($class)` of a node answers: the "other side" of a call node.
     *
     * @param class-string $className
     */
    public function register(string $className, object $facade): void
    {
        $this->facades[$className] = $facade;
    }

    /**
     * @param class-string $className
     */
    public function facade(string $className): ?object
    {
        return $this->facades[$className] ?? null;
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
