<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Data;

use JardisSupport\Contract\Kernel\ResponseStatus;
use JardisSupport\Workflow\Exception\ConstructionException;

/**
 * Immutable result of one node step: either a route (with data) or a reject (4xx, ends the run).
 *
 * A rejected result carries no route; the engine ends the loop on it. Events travel
 * with the result and are collected by the runner - this class never dispatches.
 */
final readonly class NodeResult
{
    /**
     * @param array<string, mixed> $context
     * @param list<string> $errors
     * @param list<object> $events
     */
    private function __construct(
        private ?Route $route,
        private mixed $data,
        private ?ResponseStatus $status,
        private ?string $messageKey,
        private array $context,
        private array $errors,
        private array $events,
    ) {
    }

    /**
     * Success: carries the data and takes {@see Route::OnTrue}.
     *
     * @param array<array-key, mixed>|object $data
     */
    public static function ok(array|object $data): self
    {
        return new self(Route::OnTrue, $data, null, null, [], [], []);
    }

    public static function route(Route $route, mixed $data = null): self
    {
        return new self($route, $data, null, null, [], [], []);
    }

    /**
     * Ends the run with a 4xx answer; the engine does not route a rejected result.
     *
     * @param array<string, mixed> $context
     * @param list<string> $errors
     * @throws ConstructionException when $status is not 4xx
     */
    public static function reject(
        ResponseStatus $status,
        string $messageKey,
        array $context = [],
        array $errors = []
    ): self {
        if ($status->value < 400 || $status->value > 499) {
            throw new ConstructionException(sprintf(
                'A reject needs a 4xx status, got %d (%s)',
                $status->value,
                $status->name
            ));
        }

        return new self(null, null, $status, $messageKey, $context, $errors, []);
    }

    /**
     * Returns a new instance carrying the given events (replaces earlier ones).
     */
    public function withEvents(object ...$events): self
    {
        return new self(
            $this->route,
            $this->data,
            $this->status,
            $this->messageKey,
            $this->context,
            $this->errors,
            array_values($events)
        );
    }

    /** The route this result takes; null exactly when the result is a reject. */
    public function takenRoute(): ?Route
    {
        return $this->route;
    }

    public function data(): mixed
    {
        return $this->data;
    }

    public function isRejected(): bool
    {
        return $this->status !== null;
    }

    /** The 4xx status of a reject; null for a routed result. */
    public function status(): ?ResponseStatus
    {
        return $this->status;
    }

    public function messageKey(): ?string
    {
        return $this->messageKey;
    }

    /**
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return $this->context;
    }

    /**
     * @return list<string>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * @return list<object>
     */
    public function events(): array
    {
        return $this->events;
    }
}
