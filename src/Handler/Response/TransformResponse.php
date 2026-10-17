<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Handler\Response;

use DateTimeImmutable;
use JardisSupport\Contract\Kernel\ContextResponseInterface;
use JardisSupport\Contract\Kernel\DomainResponseInterface;
use JardisSupport\Contract\Kernel\EventScope;
use JardisSupport\Contract\Kernel\ResponseStatus;
use JardisSupport\Workflow\Data\Response\DomainResponse;
use LogicException;
use Psr\Clock\ClockInterface;
use SplObjectStorage;

/**
 * Transforms ContextResponses into an immutable DomainResponse.
 *
 * Aggregates data, events and errors from a ContextResponse tree.
 * Determines the appropriate ResponseStatus from collected errors.
 * Builds metadata (duration, contexts, timestamp, version).
 *
 * Atomic closure - one entry point. Time comes from the injected PSR-20
 * clock: the instant of construction starts the duration, the instant of
 * the call stamps the timestamp.
 */
final class TransformResponse
{
    private readonly DateTimeImmutable $startedAt;

    public function __construct(
        private readonly ClockInterface $clock,
        private readonly string $version = ''
    ) {
        $this->startedAt = $clock->now();
    }

    /**
     * @throws LogicException
     */
    public function __invoke(
        ContextResponseInterface $result,
        ?ResponseStatus $status = null
    ): DomainResponseInterface {
        $data = $this->rootPayload($this->aggregateData($result, $this->visited()));
        $eventsByScope = $this->aggregateEvents($result);
        $errors = $this->aggregateErrors($result, $this->visited());
        $contexts = $this->collectContexts($result, $this->visited());

        $resolvedStatus = $status ?? $this->resolveStatus($errors);

        $finishedAt = $this->clock->now();
        $metadata = [
            'duration' => $this->elapsedMilliseconds($this->startedAt, $finishedAt),
            'contexts' => $contexts,
            'timestamp' => $finishedAt->format('c'),
            'version' => $this->version,
        ];

        return new DomainResponse($resolvedStatus, $data, [], $errors, $metadata, $eventsByScope);
    }

    /**
     * Elapsed time between two instants in milliseconds, two decimals.
     */
    private function elapsedMilliseconds(DateTimeImmutable $from, DateTimeImmutable $to): float
    {
        $microseconds = (int) $to->format('Uu') - (int) $from->format('Uu');

        return round($microseconds / 1000, 2);
    }

    /**
     * Aggregate data from result and all sub-results recursively.
     *
     * @param SplObjectStorage<ContextResponseInterface, true> $visited
     * @return array<string, array<string, mixed>>
     */
    private function aggregateData(ContextResponseInterface $result, SplObjectStorage $visited): array
    {
        $data = $result->getData();

        foreach ($this->subResults($result, $visited) as $subResult) {
            $data = array_merge($data, $this->aggregateData($subResult, $visited));
        }

        return $data;
    }

    /**
     * Flat payload of the root context. Exactly one context may carry data; the
     * payload is that context's data itself (it names its own type as `@type`).
     * More than one data-carrying context is ambiguous and never merged silently.
     *
     * @param array<string, array<string, mixed>> $contextData Data keyed by context
     * @return array<string, mixed>
     * @throws LogicException when more than one context carries data
     */
    private function rootPayload(array $contextData): array
    {
        $carriers = array_filter($contextData, static fn (array $payload): bool => $payload !== []);

        if (count($carriers) > 1) {
            throw new LogicException(sprintf(
                'Response data is ambiguous: %d contexts carry data (%s), only the root context may.',
                count($carriers),
                implode(', ', array_keys($carriers))
            ));
        }

        return $carriers === [] ? [] : array_values($carriers)[0];
    }

    /**
     * Aggregate events from the result tree, grouped by scope value, then context.
     *
     * Each scope is walked over its own fresh visited-set; empty scopes are
     * dropped so getEvents(Domain) stays empty until a Domain producer exists.
     *
     * @return array<string, array<string, array<int, object>>>
     */
    private function aggregateEvents(ContextResponseInterface $result): array
    {
        $byScope = [];

        foreach (EventScope::cases() as $scope) {
            $scopeEvents = $this->aggregateEventsForScope($result, $scope, $this->visited());
            if ($scopeEvents !== []) {
                $byScope[$scope->value] = $scopeEvents;
            }
        }

        return $byScope;
    }

    /**
     * Aggregate the events of a single scope from result and all sub-results recursively.
     *
     * @param SplObjectStorage<ContextResponseInterface, true> $visited
     * @return array<string, array<int, object>>
     */
    private function aggregateEventsForScope(
        ContextResponseInterface $result,
        EventScope $scope,
        SplObjectStorage $visited
    ): array {
        $events = [];

        foreach ($result->getEvents($scope) as $context => $contextEvents) {
            if (!empty($contextEvents)) {
                $events[$context] = $contextEvents;
            }
        }

        foreach ($this->subResults($result, $visited) as $subResult) {
            $events = array_merge($events, $this->aggregateEventsForScope($subResult, $scope, $visited));
        }

        return $events;
    }

    /**
     * Aggregate errors from result and all sub-results recursively.
     *
     * @param SplObjectStorage<ContextResponseInterface, true> $visited
     * @return array<string, array<int, string>>
     */
    private function aggregateErrors(ContextResponseInterface $result, SplObjectStorage $visited): array
    {
        $errors = [];

        $resultErrors = $result->getErrors();
        foreach ($resultErrors as $context => $contextErrors) {
            if (!empty($contextErrors)) {
                $errors[$context] = $contextErrors;
            }
        }

        foreach ($this->subResults($result, $visited) as $subResult) {
            $errors = array_merge($errors, $this->aggregateErrors($subResult, $visited));
        }

        return $errors;
    }

    /**
     * Collect all context names from result tree.
     *
     * @param SplObjectStorage<ContextResponseInterface, true> $visited
     * @return array<int, string>
     */
    private function collectContexts(ContextResponseInterface $result, SplObjectStorage $visited): array
    {
        $contexts = array_keys($result->getData());

        foreach ($this->subResults($result, $visited) as $subResult) {
            $contexts = array_merge($contexts, $this->collectContexts($subResult, $visited));
        }

        return array_values(array_unique($contexts));
    }

    /**
     * Returns sub-results, skipping already visited nodes to prevent infinite recursion.
     *
     * @param SplObjectStorage<ContextResponseInterface, true> $visited
     * @return array<int, ContextResponseInterface>
     */
    private function subResults(ContextResponseInterface $result, SplObjectStorage $visited): array
    {
        $filtered = [];

        foreach ($result->getResults() as $subResult) {
            if (!$visited->contains($subResult)) {
                $visited->attach($subResult);
                $filtered[] = $subResult;
            }
        }

        return $filtered;
    }

    /**
     * @return SplObjectStorage<ContextResponseInterface, true>
     */
    private function visited(): SplObjectStorage
    {
        /** @var SplObjectStorage<ContextResponseInterface, true> */
        $storage = new SplObjectStorage();
        return $storage;
    }

    /**
     * Resolve status from errors. No errors = Success, otherwise ValidationError.
     *
     * @param array<string, array<int, string>> $errors
     */
    private function resolveStatus(array $errors): ResponseStatus
    {
        return empty($errors) ? ResponseStatus::Success : ResponseStatus::ValidationError;
    }
}
