<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Handler\Node;

use JardisSupport\Contract\Kernel\DomainResponseInterface;
use JardisSupport\Contract\Kernel\ResponseStatus;
use JardisSupport\Workflow\Data\NodeResult;

/**
 * Maps the answer of a called domain operation to a node result.
 *
 * 2xx becomes a success carrying the data, 4xx a reject with the message key the callee named
 * in its data (empty when it named none); the callee's error messages are flattened into one list,
 * their field keys are dropped. Every other status is a technical failure and throws.
 */
final class MapResponse
{
    /**
     * @throws \RuntimeException on a 5xx or any status without a node meaning
     */
    public function __invoke(DomainResponseInterface $response): NodeResult
    {
        $status = $response->getStatus();
        $data = $response->getData();
        $reject = ResponseStatus::tryFrom($status);

        return match (true) {
            $status >= 200 && $status < 300 => NodeResult::ok($data),
            $status >= 400 && $status < 500 && $reject !== null => NodeResult::reject(
                $reject,
                is_string($data['messageKey'] ?? null) ? $data['messageKey'] : '',
                array_diff_key($data, ['messageKey' => true]),
                array_merge([], ...array_values($response->getErrors()))
            ),
            default => throw new \RuntimeException(sprintf('Operation answered with status %d', $status)),
        };
    }
}
