<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Data\Response;

use JardisSupport\Contract\Kernel\DomainResponseInterface;
use JardisSupport\Contract\Kernel\EventScope;
use JardisSupport\Contract\Kernel\ResponseStatus;

/**
 * Immutable Domain Response.
 *
 * Final response from a domain operation, built by TransformResponse.
 * Contains aggregated data, events, errors and metadata from all ContextResponses.
 */
readonly class DomainResponse implements DomainResponseInterface
{
    /**
     * Single source of truth: events grouped by scope value, then by context.
     *
     * @var array<string, array<string, array<int, object>>>
     */
    private array $byScope;

    /**
     * @param ResponseStatus $status Result status
     * @param array<string, mixed> $data Flat payload of the root context (the business object, typed by `@type`)
     * @param array<string, array<int, object>> $events Legacy flat event map, keyed by context (classified as Internal)
     * @param array<string, array<int, string>> $errors All errors, keyed by context
     * @param array<string, mixed> $metadata Response metadata
     * @param array<string, array<string, array<int, object>>> $eventsByScope Events grouped by scope, then context
     */
    public function __construct(
        private ResponseStatus $status,
        private array $data,
        array $events,
        private array $errors,
        private array $metadata,
        array $eventsByScope = []
    ) {
        // $eventsByScope is canonical (built by TransformResponse); the flat $events
        // is the legacy fallback, classified as Internal and ignored when both are set.
        $this->byScope = $eventsByScope !== []
            ? $eventsByScope
            : ($events !== [] ? [EventScope::Internal->value => $events] : []);
    }

    public function isSuccess(): bool
    {
        return $this->status === ResponseStatus::Success
            || $this->status === ResponseStatus::Created
            || $this->status === ResponseStatus::NoContent;
    }

    public function getStatus(): int
    {
        return $this->status->value;
    }

    /**
     * Flat payload: the business object itself (typed by `@type`), not a map keyed by context.
     *
     * @return array<string, mixed>
     */
    public function getData(): array
    {
        return $this->data;
    }

    /**
     * Without a scope, collapses the scope axis back to the flat context-keyed
     * map (structurally unchanged from the pre-scope behaviour). With a scope,
     * returns only the events of that scope.
     *
     * @return array<string, array<int, object>>
     */
    public function getEvents(?EventScope $scope = null): array
    {
        if ($scope !== null) {
            return $this->byScope[$scope->value] ?? [];
        }

        $flat = [];
        foreach ($this->byScope as $byContext) {
            foreach ($byContext as $context => $events) {
                $flat[$context] = array_merge($flat[$context] ?? [], $events);
            }
        }

        return $flat;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * @return array<string, mixed>
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }
}
