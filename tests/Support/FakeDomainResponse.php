<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Tests\Support;

use JardisSupport\Contract\Kernel\DomainResponseInterface;
use JardisSupport\Contract\Kernel\EventScope;

/**
 * Test double of a callee's answer: a status with data and errors, nothing else.
 */
final readonly class FakeDomainResponse implements DomainResponseInterface
{
    /**
     * @param array<string, mixed> $data
     * @param array<string, array<int, string>> $errors
     */
    public function __construct(private int $status, private array $data = [], private array $errors = [])
    {
    }

    public function isSuccess(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    public function getData(): array
    {
        return $this->data;
    }

    public function getEvents(?EventScope $scope = null): array
    {
        return [];
    }

    public function getErrors(): array
    {
        return $this->errors;
    }

    public function getMetadata(): array
    {
        return [];
    }
}
