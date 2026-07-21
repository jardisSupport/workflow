<?php

declare(strict_types=1);

namespace JardisSupport\Workflow;

use JardisSupport\Contract\Workflow\AggregateResponse;
use JardisSupport\Contract\Workflow\WorkflowContextInterface;
use JardisSupport\Contract\Workflow\WorkflowResultInterface;

// phpcs:disable Squiz.Commenting.ClassComment.TagNotAllowed -- class-level @template needed for generic payload typing
/**
 * Recommended typed state object for a process orchestrator's workflow run.
 *
 * Carries the speaking three-step payload → original → modified through the run:
 *  - `payload`  the typed process input (the command/DTO that started the run),
 *  - `original` the ordered read-projections collected from aggregate facades
 *               (Fan-in), discriminated by object type via instanceof,
 *  - `modified` the ordered write-intent commands produced by the process middle
 *               (Fan-out), each routed back to its facade by the caller.
 *
 * This is the generic *pattern* — not a domain type: the engine never inspects
 * these slots. It is generic over the payload type ({@see self} `@template TPayload`)
 * so a caller annotates `WorkflowState<ConcreteDto>` and reads `$state->payload`
 * fully typed, while the class body exists exactly once instead of being emitted
 * per process.
 *
 * It implements the full {@see WorkflowContextInterface} by delegating the chain
 * and mantle-slot methods to an internal {@see WorkflowContext}, so the engine can
 * accept it as a context and return it type-compatibly.
 *
 * @template TPayload of object
 */
final class WorkflowState implements WorkflowContextInterface
{
    // phpcs:enable Squiz.Commenting.ClassComment.TagNotAllowed

    /**
     * The typed process input. Set by the caller immediately after construction.
     *
     * @var TPayload
     */
    public object $payload;

    /**
     * Ordered Fan-in read-projections. Mixed aggregate types keep their order;
     * the type-blind middle discriminates by object type (instanceof).
     *
     * @var list<AggregateResponse>
     */
    public array $original = [];

    /**
     * Ordered Fan-out write-intent commands, routed to their facade by type.
     *
     * @var list<object>
     */
    public array $modified = [];

    private WorkflowContext $companion;

    public function __construct()
    {
        $this->companion = new WorkflowContext();
    }

    public function addOriginal(AggregateResponse $response): void
    {
        $this->original[] = $response;
    }

    public function addModified(object $command): void
    {
        $this->modified[] = $command;
    }

    public function append(string $handlerFqcn, WorkflowResultInterface $result): void
    {
        $this->companion->append($handlerFqcn, $result);
    }

    public function getPrevious(): ?WorkflowResultInterface
    {
        return $this->companion->getPrevious();
    }

    public function getLatest(string $handlerFqcn): ?WorkflowResultInterface
    {
        return $this->companion->getLatest($handlerFqcn);
    }

    /**
     * @return list<WorkflowResultInterface>
     */
    public function getAll(string $handlerFqcn): array
    {
        return $this->companion->getAll($handlerFqcn);
    }

    /**
     * @return list<WorkflowResultInterface>
     */
    public function getChain(): array
    {
        return $this->companion->getChain();
    }

    public function reference(): mixed
    {
        return $this->companion->reference();
    }

    public function setReference(mixed $value): void
    {
        $this->companion->setReference($value);
    }

    public function response(): mixed
    {
        return $this->companion->response();
    }

    public function setResponse(mixed $value): void
    {
        $this->companion->setResponse($value);
    }

    public function getException(): ?\Throwable
    {
        return $this->companion->getException();
    }

    public function setException(\Throwable $e): void
    {
        $this->companion->setException($e);
    }
}
