---
name: support-workflow
description: jardissupport/workflow - Multi-step process orchestration. Use when working with Workflow, step execution, or jardissupport/workflow.
user-invocable: false
zone: post-active
persona: C
prerequisites: [rules-architecture, rules-patterns]
next: [platform-workflow]
---

# WORKFLOW_COMPONENT_SKILL
> `jardissupport/workflow` | NS: `JardisSupport\Workflow` | PHP 8.2+

## ARCHITECTURE
```
Workflow(__invoke($config, $data = null, $context = null))
  → handler factory receives ($fqcn, $data); handlers themselves see only WorkflowContext
  → context ??= new WorkflowContext()   // caller may inject one (e.g. a typed WorkflowState); engine returns it
  → nodes[0] handler → WorkflowResult (one of seven ON_* statuses)
  → context->append($fqcn, $result)   // pushes new entry; never overwrites
  → determineNextHandler(result, config):
      transitions = config->getTransitions($currentHandler)
      next        = transitions[$result->getStatus()] ?? null
      isRegisteredNode(next, config)? → next : null   // R5 routing-safety
  → loop until no next node
  → return WorkflowContextInterface
```

Contracts: `jardissupport/contracts` (`WorkflowInterface`, `WorkflowConfigInterface`, `WorkflowContextInterface`, `WorkflowResultInterface`, `WorkflowBuilderInterface`, `WorkflowNodeBuilderInterface`)

## CLASSES
| Class | Role |
|-------|------|
| `Workflow` | Engine: pushes WorkflowContext into params, executes nodes, returns context |
| `WorkflowConfig` | Node registry + transition maps; optional `strictRouting` opt-in (default `false`, see Builder section) |
| `WorkflowContext` | Mutable ordered execution log; FQCN-keyed lookups via `getLatest`/`getAll` (implements `WorkflowContextInterface`) |
| `WorkflowState<TPayload>` | Recommended typed state object for a process orchestrator: carries `payload → original → modified`; implements `WorkflowContextInterface` by delegating to an internal `WorkflowContext`, so it can be passed into the engine as `$context` |
| `WorkflowResult` | VO: one of seven `ON_*` statuses + data (implements `WorkflowResultInterface`) |
| `WorkflowBuilder` | Fluent config builder (implements `WorkflowBuilderInterface`) |
| `WorkflowNodeBuilder` | Transition config per node (implements `WorkflowNodeBuilderInterface`) |

## API

### WorkflowResult
```php
new WorkflowResult(WorkflowResult::ON_SUCCESS, $data);    // → onSuccess routing (in loops: "another iteration")
new WorkflowResult(WorkflowResult::ON_FAIL,    $errors);  // → onFail routing
new WorkflowResult(WorkflowResult::ON_SKIP,    $data);    // → onSkip routing (handler not applicable)
new WorkflowResult(WorkflowResult::ON_EVENT,   $payload); // → onEvent routing (async hand-off)
new WorkflowResult(WorkflowResult::ON_EXIT,    $data);    // → onExit routing (loop/block terminated)

$result->getStatus();              // string — always one of the seven ON_* constants
$result->getData();                // mixed
$result->getHandlerFqcn();         // ?string — stamped by engine via withHandler() during append()
$result->withHandler($fqcn);       // static — new immutable instance with the FQCN set
```

**Status constants (the only seven valid values):** `ON_SUCCESS`, `ON_FAIL`, `ON_TIMEOUT`, `ON_SKIP`, `ON_CANCEL`, `ON_EVENT`, `ON_EXIT`. Any other string passed to the constructor raises `InvalidArgumentException`.

**Loop pattern:** in a self-loop, the body handler typically returns `ON_SUCCESS` for "another iteration" (self-edge) and `ON_EXIT` for "loop is done, continue with the outer flow" (edge to the post-loop node).

### WorkflowContext
Flat ordered execution log — every handler invocation appends a new handler-stamped entry. Re-invocations of the same handler (retry loops, cross-branch revisits) **never overwrite earlier entries**, so history is lossless. Three mantle slots carry state between the flow's entry/final companions and the routing graph.

```php
$context->append($fqcn, $result);   // void — stamps via withHandler() and pushes; called by engine
$context->getPrevious();            // ?WorkflowResultInterface — last appended (immediate predecessor)
$context->getLatest($fqcn);         // ?WorkflowResultInterface — most recent invocation of that handler
$context->getAll($fqcn);            // list<WorkflowResultInterface> — every invocation, in execution order
$context->getChain();               // list<WorkflowResultInterface> — flat ordered log, each stamped

$context->reference();              // mixed — pre-loaded data (set by flow entry companion); null default
$context->setReference($value);
$context->response();               // mixed — flow's final answer (set by flow final companion); null default
$context->setResponse($value);
$context->getException();           // ?\Throwable — captured by orchestrator before re-throw; null on clean run
$context->setException($e);
```

### WorkflowState\<TPayload\>
Recommended typed state object when a **process orchestrator** drives the engine. It implements the full `WorkflowContextInterface` (delegating chain + mantle-slot methods to an internal `WorkflowContext`), so it can be passed in as the engine's third `$context` argument and returned type-compatibly — while exposing a speaking, fully typed three-step. The class body exists **once**; it is not emitted per process. The engine never inspects the three slots.

```php
use JardisSupport\Workflow\WorkflowState;
use JardisSupport\Contract\Workflow\AggregateResponse;

/** @var WorkflowState<CreateOrderCommand> $state */
$state = new WorkflowState();
$state->payload = $command;          // TPayload — typed process input (the DTO/command that started the run)

$state->addOriginal($aggResponse);   // push a Fan-in read-projection (AggregateResponse); order preserved
$state->original;                    // list<AggregateResponse> — middle discriminates by type (instanceof)

$state->addModified($writeCommand);  // push a Fan-out write-intent command; caller routes each to its facade
$state->modified;                    // list<object>

$result = $workflow($config, $data, $state);   // engine appends results into $state, returns it
// $result === $state — read $state->payload fully typed, plus the usual chain/mantle-slot methods
```

- `payload` is a **public** property set by the caller right after construction (no constructor arg).
- `original` / `modified` are appended via `addOriginal()` / `addModified()`, read as plain `list`s.
- All `WorkflowContextInterface` methods (`append`, `getPrevious`, `getLatest`, `getAll`, `getChain`, `reference`/`setReference`, `response`/`setResponse`, `getException`/`setException`) delegate to the internal companion context.

### Builder
```php
$config = (new WorkflowBuilder())
    ->node(ValidateHandler::class)
        ->onSuccess(PaymentHandler::class)
        ->onFail(RejectHandler::class)
    ->node(PaymentHandler::class)
        ->onSuccess(ShippingHandler::class)
        ->onFail(NotifyHandler::class)
        ->onFail(PaymentHandler::class)   // (illustrative: a self-loop replaces ON_RETRY of old API)
    ->node(ShippingHandler::class)
    ->node(RejectHandler::class)
    ->node(NotifyHandler::class)
    ->build();  // WorkflowConfigInterface
```
`WorkflowNodeBuilder` methods: `onSuccess()`, `onFail()`, `onTimeout()`, `onSkip()`, `onCancel()`, `onEvent()`, `onExit()`, `node()`, `build()`

**R5 routing-safety:** the engine resolves the next handler via
`transitions[$result->getStatus()]`; if that target is not itself registered
via `node()`/`addNode()`, the engine returns the current context without
attempting to dispatch. Use this to model hand-off-only nodes whose successors
live outside the in-process pipeline.

**Opt-in strict routing:** `new WorkflowConfig(strictRouting: true)` (constructor
param on `WorkflowConfig`, default `false`). Requires every status a handler can
emit to be a declared transition key — `'STATUS' => null` is a legitimate
declared terminal end; a status with no key at all raises
`JardisSupport\Workflow\Exception\UnroutedStatusException` (`getNode()`/`getStatus()`).
Non-strict default stays byte-identical to pre-existing behaviour; the R5
hand-off above is unaffected by the flag either way.

### Handler contract
```php
use JardisSupport\Contract\Workflow\WorkflowContextInterface;

class MyHandler {
    public function __construct(private readonly Order $order) {}

    public function __invoke(WorkflowContextInterface $context): WorkflowResult {
        // single-arg signature — the context is everything the handler sees from the engine.
        // Per-run input ($order here) is wired in by the handler factory (constructor injection
        // or — in a generated Context-Familie setup — $this->payload() pulled from a context()-spawned family-internal class).
        $previous = $context->getPrevious();             // result of the handler that routed here
        $myHistory = $context->getAll(self::class);      // prior invocations of this handler (for retry counting)
        return new WorkflowResult(WorkflowResult::ON_SUCCESS, ['key' => 'value']);
    }
}
```
- Must be callable (`__invoke`) and return `WorkflowResultInterface` (canonical: `WorkflowResult`)
- Engine passes the `WorkflowContext` as the **single** argument to every invocation
- Idiomatic retry counter: `$attempt = count($context->getAll(self::class)) + 1;`
- `InvalidArgumentException` thrown if handler does not return `WorkflowResultInterface`

### Workflow execution
```php
$workflow = new Workflow();                                                          // direct, new $cls()
$workflow = new Workflow(fn(string $cls, mixed $data) => $container->get($cls));     // with factory

$context = $workflow($config, $perRunData);  // single-shot, returns fresh WorkflowContext
// $context->getPrevious()              → WorkflowResult of last executed handler
// $context->getLatest(Foo::class)      → most recent invocation of Foo (or null)
// $context->getAll(Foo::class)         → every invocation of Foo (list)
// $context->getChain()                 → full ordered execution log (flat, stamped)
// $context->reference() / response() / getException()  → mantle slots

$context = $workflow($config, $perRunData, $state);  // inject your own context (e.g. WorkflowState); engine returns it
```
The third `$context` argument is **optional and non-breaking**: omit it and the engine creates a fresh `WorkflowContext` per run (default). Provide one — typically a typed `WorkflowState` — and the engine appends every handler result into it and returns *that* instance, letting a process orchestrator carry typed state through the run.

Iteration over inputs is the **caller's** job — call `$workflow($config, $item)` once per item; the engine never reuses contexts.

## LAYER RULES
- Application: build `WorkflowConfig`, execute `Workflow`
- Domain: NEVER imports Workflow classes
- Handlers: thin orchestration — delegate to domain services
- `WorkflowResult`: return type contract, not a domain concept
- `WorkflowContext`: passed by reference (mutable); handlers READ it, the engine WRITES it
