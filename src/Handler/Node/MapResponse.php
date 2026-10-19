<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Handler\Node;

use JardisSupport\Contract\Kernel\DomainResponseInterface;
use JardisSupport\Contract\Kernel\ResponseStatus;
use JardisSupport\Workflow\Data\NodeResult;
use JardisSupport\Workflow\Data\Route;
use JardisSupport\Workflow\Exception\CalleeFailureException;

/**
 * Maps the answer of a called domain operation - an aggregate command, or the inner door of a sub-process -
 * to a node result.
 *
 * 2xx becomes a success carrying the data and the events of the callee, so they travel up to the answer of
 * the run. 4xx becomes a reject with the SAME status whose context is the plain context of the callee: the
 * form of the callee's data is unpacked (`@type` dropped, the inner `context` lifted, the form's own fields -
 * 400 `fields`, 409 `reason`/`aggregate`/`violated`, 422 `rule` - kept under their own keys), so the process
 * answers the same form again. With `$onReject` a 4xx becomes a route instead: the dev decision to carry on
 * rather than refuse. The callee's message key travels only where its form carries one (422); for 400, 404 and
 * 409 it is left to the node's declaration ({@see \JardisSupport\Workflow\Data\ProcessDefinition::rejects()}).
 * Any other 4xx (401, 403, ...) has no form: its data passes through unchanged as the context, a key in it
 * stays where it is. The callee's error messages are flattened into one list, their field keys are dropped.
 * Every other status is a technical failure and throws; so is a 5xx even with `$onReject`. A 5xx throws a
 * {@see CalleeFailureException}, which carries the marker "cause already reported" because the inner door has
 * logged it; any other status throws a plain `\RuntimeException`.
 */
final class MapResponse
{
    /**
     * @throws CalleeFailureException on a 5xx
     * @throws \RuntimeException on any other status without a node meaning
     */
    public function __invoke(DomainResponseInterface $response, ?Route $onReject = null): NodeResult
    {
        $status = $response->getStatus();
        $data = $response->getData();
        $reject = ResponseStatus::tryFrom($status);
        if ($status >= 200 && $status < 300) {
            return NodeResult::ok($data)->withEvents(...array_merge([], ...array_values($response->getEvents())));
        }
        if ($status >= 500 && $status < 600) {
            throw new CalleeFailureException(sprintf('Operation answered with status %d', $status));
        }
        if ($status < 400 || $status >= 500 || $reject === null) {
            throw new \RuntimeException(sprintf('Operation answered with status %d', $status));
        }

        if ($onReject !== null) {
            return NodeResult::route($onReject);
        }

        return NodeResult::reject(
            $reject,
            $this->messageKey($reject, $data),
            $this->context($reject, $data),
            array_merge([], ...array_values($response->getErrors()))
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private function messageKey(ResponseStatus $status, array $data): string
    {
        return $status === ResponseStatus::RuleViolation && is_string($data['messageKey'] ?? null)
            ? $data['messageKey']
            : '';
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function context(ResponseStatus $status, array $data): array
    {
        return match ($status) {
            ResponseStatus::ValidationError => array_intersect_key($data, ['fields' => true]),
            ResponseStatus::NotFound => $this->plain($data),
            ResponseStatus::Conflict => array_intersect_key(
                $data,
                ['reason' => true, 'aggregate' => true, 'violated' => true]
            ) + $this->plain($data),
            ResponseStatus::RuleViolation => array_intersect_key($data, ['rule' => true]) + $this->plain($data),
            default => $data,
        };
    }

    /**
     * The inner `context` of a callee's form as an array (an empty one arrives as an object).
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function plain(array $data): array
    {
        $context = $data['context'] ?? [];
        $context = is_object($context) ? get_object_vars($context) : $context;

        return is_array($context) ? $context : [];
    }
}
