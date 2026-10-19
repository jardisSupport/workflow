<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Tests\Support;

use JardisSupport\Contract\Kernel\DomainResponseInterface;
use JardisSupport\Contract\Kernel\EventScope;

/**
 * Test double of a callee's answer: a status with data, errors and events, nothing else.
 */
final readonly class FakeDomainResponse implements DomainResponseInterface
{
    /**
     * @param array<string, mixed> $data
     * @param array<string, array<int, string>> $errors
     * @param list<object> $events Domain events, carried under one context key, as the contract keys events by context
     * @param list<object> $internalEvents Internal events (write gesture of an aggregate), same keying
     */
    public function __construct(
        private int $status,
        private array $data = [],
        private array $errors = [],
        private array $events = [],
        private array $internalEvents = []
    ) {
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
        $events = match ($scope) {
            EventScope::Domain => $this->events,
            EventScope::Internal => $this->internalEvents,
            default => array_merge($this->events, $this->internalEvents),
        };

        return $events === [] ? [] : ['' => $events];
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
