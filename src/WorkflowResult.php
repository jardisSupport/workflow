<?php

declare(strict_types=1);

namespace JardisSupport\Workflow;

use JardisSupport\Contract\Workflow\WorkflowResultInterface;

/**
 * Value Object representing the result of a workflow handler execution.
 *
 * Provides explicit control over workflow transitions by requiring handlers
 * to return a WorkflowResult instead of arbitrary values. This eliminates
 * ambiguity in truthy/falsy evaluation and enables named transitions.
 *
 * Usage:
 *   return new WorkflowResult(WorkflowResult::ON_SUCCESS, $data);
 *   return new WorkflowResult(WorkflowResult::ON_FAIL, $errors);
 *   return new WorkflowResult(WorkflowResult::ON_EVENT, $eventPayload);
 */
final class WorkflowResult implements WorkflowResultInterface
{
    /** Erfolgreicher Abschluss des Handlers. */
    public const ON_SUCCESS = 'onSuccess';

    /** Fachlicher Misserfolg (Validierung, Geschaeftsregel). */
    public const ON_FAIL = 'onFail';

    /** Geplanter Recovery-Pfad: Service-Side-Timeout in fachliches Routing uebersetzt. */
    public const ON_TIMEOUT = 'onTimeout';

    /** Handler nicht anwendbar — Flow ueberspringt zum Re-Konvergenz-Punkt. */
    public const ON_SKIP = 'onSkip';

    /** Fachlicher Abbruch (Stornierung, Zustimmung zurueckgezogen) — Cleanup-Pfad. */
    public const ON_CANCEL = 'onCancel';

    /** Aktiver async-Hand-off via DomainEvent — Folge-Runs entstehen extern. */
    public const ON_EVENT = 'onEvent';

    /** Schleife/Block terminiert — weiter im umgebenden Flow (Loop-Exit-Kante). */
    public const ON_EXIT = 'onExit';

    /**
     * @param string $status The result status (one of the ON_* constants)
     * @param mixed $data Optional data payload from the handler
     * @param ?string $handlerFqcn Handler FQCN — stamped by the workflow engine via withHandler();
     *                             user code should leave this null when constructing a result.
     */
    public function __construct(
        private readonly string $status,
        private readonly mixed $data = null,
        private readonly ?string $handlerFqcn = null
    ) {
        if (!$this->isValidStatus($status)) {
            throw new \InvalidArgumentException(
                sprintf('Invalid workflow status "%s"', $status)
            );
        }
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getData(): mixed
    {
        return $this->data;
    }

    public function getHandlerFqcn(): ?string
    {
        return $this->handlerFqcn;
    }

    public function withHandler(string $fqcn): static
    {
        return new self($this->status, $this->data, $fqcn);
    }

    private function isValidStatus(string $status): bool
    {
        return in_array($status, [
            self::ON_SUCCESS,
            self::ON_FAIL,
            self::ON_TIMEOUT,
            self::ON_SKIP,
            self::ON_CANCEL,
            self::ON_EVENT,
            self::ON_EXIT,
        ], true);
    }
}
