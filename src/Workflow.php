<?php

declare(strict_types=1);

namespace JardisSupport\Workflow;

use InvalidArgumentException;
use JardisSupport\Contract\Workflow\WorkflowConfigInterface;
use JardisSupport\Contract\Workflow\WorkflowContextInterface;
use JardisSupport\Contract\Workflow\WorkflowInterface;
use JardisSupport\Contract\Workflow\WorkflowResultInterface;
use JardisSupport\Workflow\Exception\UnroutedStatusException;

/**
 * Executes a configured chain of workflow handlers.
 *
 * The engine is stateless and single-shot: every invocation builds a fresh
 * WorkflowContext, walks the routing graph, stamps each result with its
 * producing handler's FQCN, and returns the context. Callers (typically the
 * generated flow orchestrator) are responsible for iteration, aggregation
 * and any external state — the engine itself never reuses contexts and
 * never inspects domain payloads.
 *
 * Handler instantiation is delegated to an optional factory closure that
 * receives both the FQCN and the optional per-run $data. A typical wiring
 * inside a BoundedContext spawns a fresh BC with $data as payload via
 * `$this->context($cls, $data)`, so handlers see $data via $this->payload();
 * when $data is null the factory falls back to `$this->handle($cls)` and
 * the outer payload is preserved.
 *
 * Routing is purely named: every transition is keyed by one of the seven ON_*
 * constants returned by the handler (onSuccess, onFail, onTimeout, onSkip,
 * onCancel, onEvent, onExit). When no transition is configured for the returned
 * status — or the configured target is not itself a registered node — the
 * engine returns control to the caller.
 *
 * Opt-in strict routing: when the config reports {@see WorkflowConfig::isStrictRouting()}
 * as true, a status with no transition key at all (as opposed to a key explicitly mapped
 * to null, a declared terminal end) raises {@see UnroutedStatusException} instead of
 * silently stopping. The default (flag omitted or false) preserves pre-existing behaviour
 * byte-for-byte — including the R5 hand-off (a mapped target that is not itself a
 * registered node), which strict mode does not affect.
 */
class Workflow implements WorkflowInterface
{
    /**
     * @param ?\Closure(string $className, mixed $data): object $handlerFactory
     *        Optional factory; receives the handler FQCN and the per-run $data.
     *        When null, defaults to `new $className()` (data is ignored).
     */
    public function __construct(
        private ?\Closure $handlerFactory = null
    ) {
    }

    /**
     * @param mixed $data Optional per-run input forwarded to the handler factory.
     * @param ?WorkflowContextInterface $context Optional execution context. When omitted, a fresh
     *        WorkflowContext is created (unchanged default behaviour). When provided, the engine
     *        appends every handler result to it and returns it — letting a caller (e.g. a generated
     *        process orchestrator) carry a typed state object through the run. The engine only ever
     *        touches the narrow {@see WorkflowChainInterface} surface (append + lookups).
     * @throws InvalidArgumentException
     */
    public function __invoke(
        WorkflowConfigInterface $workflowConfig,
        mixed $data = null,
        ?WorkflowContextInterface $context = null,
    ): WorkflowContextInterface {
        $nodes = $workflowConfig->getNodes();
        $firstNode = $nodes[0] ?? null;
        $processClass = $firstNode['handler'] ?? null;

        $context ??= new WorkflowContext();

        while (is_string($processClass)) {
            /** @var class-string $processClass — guaranteed by WorkflowConfig::addNode() validation */
            $handler = $this->createHandler($processClass, $data);

            if (!is_callable($handler)) {
                throw new InvalidArgumentException(sprintf(
                    'Workflow handler %s is not callable',
                    $processClass
                ));
            }

            $workflowResult = $handler($context);

            if (!$workflowResult instanceof WorkflowResultInterface) {
                throw new InvalidArgumentException(sprintf(
                    'Workflow handler %s must return a WorkflowResultInterface instance, got %s',
                    $processClass,
                    get_debug_type($workflowResult)
                ));
            }

            $context->append($processClass, $workflowResult);

            $processClass = $this->determineNextHandler($workflowResult, $workflowConfig, $processClass);
        }

        return $context;
    }

    private function createHandler(string $processClass, mixed $data): object
    {
        if ($this->handlerFactory !== null) {
            return ($this->handlerFactory)($processClass, $data);
        }

        return new $processClass();
    }

    /**
     * Determines the next handler based on the WorkflowResult.
     *
     * Looks up the configured transition for the result's status. Returns null
     * when the current handler has no transitions at all, when the status maps
     * to an explicit null (a deliberate terminal end), or when the configured
     * target is not itself a registered node (R5-routing-safety: prevents the
     * engine from dispatching to a handler whose signature/role does not match
     * the pipeline — this hand-off remains legal in strict mode too).
     *
     * In strict-routing mode ({@see WorkflowConfig::isStrictRouting()}), a status
     * with no transition key at all — as opposed to a key explicitly present and
     * mapped to null — raises {@see UnroutedStatusException}.
     *
     * @throws UnroutedStatusException
     */
    private function determineNextHandler(
        WorkflowResultInterface $workflowResult,
        WorkflowConfigInterface $config,
        string $currentHandler
    ): ?string {
        $transitions = $config->getTransitions($currentHandler);
        if ($transitions === null) {
            return null;
        }

        $status = $workflowResult->getStatus();

        if ($this->isStrictRouting($config) && !array_key_exists($status, $transitions)) {
            throw new UnroutedStatusException($currentHandler, $status);
        }

        $next = $transitions[$status] ?? null;
        if ($next === null) {
            return null;
        }

        if (!$this->isRegisteredNode($next, $config)) {
            return null;
        }

        return $next;
    }

    private function isStrictRouting(WorkflowConfigInterface $config): bool
    {
        return $config instanceof WorkflowConfig && $config->isStrictRouting();
    }

    private function isRegisteredNode(string $fqcn, WorkflowConfigInterface $config): bool
    {
        foreach ($config->getNodes() as $node) {
            if ($node['handler'] === $fqcn) {
                return true;
            }
        }
        return false;
    }
}
