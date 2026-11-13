<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Handler\Node;

use JardisSupport\Contract\Kernel\DomainResponseInterface;
use JardisSupport\Contract\Kernel\ResponseStatus;
use JardisSupport\Workflow\Data\NodeResult;
use JardisSupport\Workflow\Data\Route;
use JardisSupport\Workflow\Exception\ExternalFailureException;

/**
 * Maps the answer of ANOTHER system (a call across a domain boundary) to a node result: a thin envelope around
 * {@see MapResponse}.
 *
 * A node never answers 401, 403 or 405: for the foreign system those are a technical failure (wrong key, missing
 * right, wrong verb), not a business refusal of the node. So 401, 403, 405, every 5xx and every status outside
 * {@see ResponseStatus} (302, 418, ...) throw an {@see ExternalFailureException} - even with `$onReject`, which
 * only ever carries on from a refusal. The exception has no marker "cause already reported", so the door answers
 * 500 and logs the status and the reason. Every other status (2xx, 400, 404, 409, 422) goes unchanged to
 * {@see MapResponse}.
 */
final class MapExternalResponse
{
    public function __construct(private readonly MapResponse $map = new MapResponse())
    {
    }

    /**
     * @throws ExternalFailureException on a technical failure of the other system
     */
    public function __invoke(DomainResponseInterface $response, ?Route $onReject = null): NodeResult
    {
        $status = $response->getStatus();
        if ($this->isTechnical($status)) {
            throw new ExternalFailureException($status, $this->reason($response));
        }

        return ($this->map)($response, $onReject);
    }

    private function isTechnical(int $status): bool
    {
        if ($status >= 200 && $status < 300) {
            return false;
        }
        $notForTheNode = [
            ResponseStatus::Unauthorized->value,
            ResponseStatus::Forbidden->value,
            ResponseStatus::MethodNotAllowed->value,
        ];

        return $status >= 500 || ResponseStatus::tryFrom($status) === null || in_array($status, $notForTheNode, true);
    }

    /**
     * The messages of the other system, whatever shape its `errors` has (a list, a string, an object).
     */
    private function reason(DomainResponseInterface $response): string
    {
        $messages = [];
        $errors = $response->getErrors();
        array_walk_recursive($errors, static function (mixed $message) use (&$messages): void {
            if (is_string($message) && $message !== '') {
                $messages[] = $message;
            }
        });

        return implode('; ', $messages);
    }
}
