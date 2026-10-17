---
name: support-workflow
description: jardissupport/workflow 2.1 - process engine for one request-response use case. Declare a node graph with ProcessDefinition, run it with ProcessRunner, get a typed ProcessOutcome; NodeResult (ok, route, reject, withEvents), ProcessContext, start validation (400/401), transaction bracket with savepoints, four failure forms, envelope data per answer (BuildResponseData, declaredMessageKey, input field paths). Use when working with ProcessRunner, ProcessDefinition, NodeResult, ProcessContext, BuildResponseData, declaredMessageKey, failure forms or jardissupport/workflow.
user-invocable: false
zone: post-active
persona: C
prerequisites: [foundation-architecture, foundation-patterns]
next: [generated-code-workflow-api]
---

# WORKFLOW_COMPONENT_SKILL
> `jardissupport/workflow` 2.1 | NS: `JardisSupport\Workflow` | PHP 8.3+ | requires `jardissupport/contracts` ^2.12, `jardissupport/scheduling` ^1.2

## Usage essentials

**Trigger:** ProcessRunner, ProcessDefinition, NodeResult, ProcessContext, ProcessOutcome, declaredMessageKey, BuildResponseData, ReadResult, Reference, ValidationFailure, NotFound, Conflict, RuleViolation, MapResponse, MapThrowable, Bracket, RuleMode, `sp_{depth}`, `process.inputInvalid`.

```
ProcessRunner::run($definition, $input, $actor, $nodeFactory, $parent = null)
  MapThrowable -> ValidateInput (400) -> CheckActor (401) -> Bracket (transaction) -> core
  core: CreateContext -> Workflow engine over the node chain -> BuildOutcome
  per node: GuardLoop -> CollectEvents -> HydrateResponse -> PassThrough -> ApplyRuleMode -> your node
  -> ProcessOutcome { status, response, errors, events, cause }
```

| Concept | Meaning |
|---------|---------|
| Node | `callable(ProcessContext): NodeResult`; kinds Action (dev code), Call (command/query/sub-process), Rule (check) |
| Node factory | `\Closure(class-string): object` (only constructs, e.g. `fn(string $c) => new $c(...)`); asked each time a node is due (also per loop pass); the package checks the object is callable, else `ConstructionException` |
| Route | `Route::OnTrue/OnFalse/OnSkip/OnTimeout/OnCancel/OnExit`; no data on the edge |
| Reject | `NodeResult::reject(4xx, ...)`: ends the run, rollback, answer = failure form. Not a route |
| End | `terminal()` = a route mapped to `null`; the last node's response is the run's response |

```php
$definition = ProcessDefinition::for(PlaceOrder::class, 'PlaceOrder')   // input class, name
    ->validate(PlaceOrderRules::class)->actor(Buyer::class)->context(CheckoutContext::class)
    ->node(CheckCredit::class)->onTrue(ReserveStock::class)->rejects(ResponseStatus::RuleViolation, 'order.creditExceeded')
    ->node(ReserveStock::class)->onTrue(ConfirmOrder::class)->response(Reserved::class)
    ->node(ConfirmOrder::class)->terminal()->response(Confirmed::class)
    ->build();

$runner = new ProcessRunner($dbConnection, $validator);          // ?DbConnectionInterface, ValidatorInterface
$runner = ProcessRunner::forKernel($kernel, $validator);         // writer from $kernel->dbConnection() (pool | PDO | null)
$outcome = $runner->run($definition, new PlaceOrder('ACME', 2), new Buyer('b-1'), $nodeFactory);
$outcome->status();   // ResponseStatus::Success
$outcome->response(); // Confirmed
$outcome->events();   // caller publishes these AFTER the run (committed)
```

### API (`src/`)

| Class | Entry points |
|-------|--------------|
| `ProcessRunner` | `new ProcessRunner(?DbConnectionInterface, ValidatorInterface)`, `forKernel(DomainKernelInterface, ValidatorInterface)`, `run(definition, input, actor, nodeFactory, parent = null)` |
| `ResolveConnection` (`Handler/Start/`) | `__invoke(DomainKernelInterface): ?DbConnectionInterface` - pool -> `getWriter()`, `PDO` -> `PdoConnection`, none -> `null` (used by `forKernel`) |
| `ProcessDefinition` (`Data/ProcessDefinition.php`) | `for(inputClass, name)`, `context`, `transactional(bool)`, `validate`, `actor`, `requires(...)`, `loopLimit(int)` (default 100), `ruleMode(node, RuleMode)`, `node`, `input(property)` (getter `getInput(node)`), `onTrue/onFalse/onSkip/onTimeout/onCancel/onExit(?target)`, `terminal(Route ...)`, `rejects(ResponseStatus, messageKey)`, `response(class, fields...)`, `build()` |
| `NodeResult` | `ok(array\|object)`, `route(Route, data = null)`, `reject(status, messageKey, context = [], errors = [])`, `withEvents(object ...)`; readers `takenRoute data isRejected status messageKey context errors events` |
| `ProcessContext` (not final) | `input actor previous latest(node) all(node) iteration(node) chain depth` |
| `ProcessOutcome` | `status response errors events cause declaredMessageKey` |
| `ReadResult<T>` | `ofList(items, total)`, `ofOne(?item)`; `items total first isEmpty` |
| `Reference` | `new Reference(root, children = [])`; `root children` |
| `Failure\FailureForm` | `ValidationFailure(messageKey, fields[], context)`, `NotFound`, `Conflict(... reason, aggregate, violated)`, `RuleViolation(rule, messageKey, context)` |
| `MapResponse` | `__invoke(DomainResponseInterface): NodeResult` (2xx ok, 4xx reject, else throws) |
| `CheckRequires` | `__invoke(DomainKernelInterface, list<string>): void` (called by the generated handler) |
| `BuildResponseData` (`Handler/Response/`) | `__invoke(ProcessOutcome, string $processName): array` - the `data` of the envelope per answer form |
| `TransformResponse` | `new TransformResponse(ClockInterface, string $version = '')`; `ContextResponse` -> `DomainResponse` |

## Full reference

**Context inheritance (W4).** `ProcessContext` is not `final`: generated processes extend it with typed getters over `latest()`/`all()`; named by `context()`, otherwise the run ends 500 (`CreateContext.php:24`). The chain is immutable (`withStep` is engine-internal). Everything else in the package is `final`.

**Start.** `ValidateInput`: only if `validate()` is set, always through the injected validator -> 400 `ValidationFailure` with `fields[]` (`field`, `reason` missing|invalid, `message`), key `process.inputInvalid`. `CheckActor`: `actor()` set and no actor -> 401, key `process.actorMissing`. 403 is a node decision. Nothing of context/nodes/transaction exists for a failed start.

**Failure forms.** 400 `ValidationFailure`, 404 `NotFound`, 409 `Conflict`, 422 `RuleViolation` (built from context keys `fields`, `reason/aggregate/violated`, `rule`); other 4xx `stdClass{messageKey, context}`; 5xx empty `stdClass` + `cause()`. `MapThrowable`: `TransientConflictException` in the owning run -> 409 `process.transientConflict` `{reason: stale_write, aggregate: <process name>}`, in a nested run rethrown; any other Throwable -> 500.

**Envelope data (`BuildResponseData`).** The one place an answer becomes the envelope's `data`; the generated handler sets it, `TransformResponse` wraps it (since 2.1):

```php
$this->result()->setData((new BuildResponseData())($outcome, $definition->getName()));
```

| Answer | `data` |
|--------|--------|
| 2xx | `@type` (process name, first letter lower case) + public fields of the response |
| 400 | `{@type: 'validation', fields}` |
| 404 | `{@type: 'notFound', context}` |
| 409 | `{@type: 'concurrencyConflict', reason, aggregate, context, violated}` |
| 422 | `{@type: 'ruleViolation', rule, messageKey, context}` |
| other 4xx | response fields as given, no `@type` |
| 5xx | empty array |

An empty `context` encodes as `{}`. The key a node names in `reject(...)` is `ProcessOutcome::declaredMessageKey()`: it joins `data['messageKey']` of every status >= 400; the package's own keys (`process.inputInvalid`, `process.actorMissing`, `process.transientConflict`) are never declared, so they stay out of `data`.

**Field paths.** `ValidateInput` answers paths below the input root (`customer`, `items[1].qty`). `->input('customer')` on a node: a 400 reject of that node prefixes its `fields[].field` with `customer.`; other statuses unchanged.

**Transaction (`Bracket`).** Opt-out `transactional(false)`: no bracket. Top-level run, none open: begin/commit-or-rollback. Transaction open (parent's or foreign): savepoint `sp_{depth}`, release on success, rollback-to on >= 400 or exception. Sub-process of a run without transaction: no bracket. A savepoint needs `SavepointInterface`, else `ConfigurationException` (500). Transactional process and no connection (`null`, kernel without database) -> `ConfigurationException` (500), key `process.connectionMissing`; opt-out runs without a connection.

**Sub-process.** A node calls `$runner->run($inner, $input, $actor, $innerNodes, $context)` (parent = surrounding context), then maps the outcome: >= 500 throw `cause()`, >= 400 reject again or route (dev decision), else `ok((array) response)->withEvents(...$outcome->events())`.

**Rule modes.** `Guard` (default): a 422 reject ends the run. `Decision`: a 422 becomes `Route::OnFalse`, every other result unchanged (`ApplyRuleMode.php:35`).

**Events.** On the `NodeResult`; collected in execution order; discarded for a rejected result and a failed run; never dispatched by the package. Publish after commit.

**Loops.** Routes may point back; leave via an explicit route (`onExit`). `iteration(node)` counts passes. Step number `loopLimit + 1` never executes -> 500, cause `LoopLimitExceededException`.

**Clock.** `TransformResponse` reads time from the injected PSR-20 clock; `SystemClock`/`FixedClock` live in `jardissupport/scheduling` >= 1.2.

## Pitfalls

| # | Pitfall | Evidence |
|---|---------|----------|
| 1 | A route without a declared key throws `UnroutedStatusException` (500). Declare every route a node can take, `terminal()` or `onX(null)` for a deliberate end | `Routing/Workflow.php:49` |
| 2 | `reject()` is not a route and takes only 4xx; any other status throws `ConstructionException` | `NodeResult.php:62-64` |
| 3 | `response(class)` needs a value for every required constructor parameter named by the fields (none named = all); missing value or unknown class -> 500 | `Context/HydrateResponse.php:54,68` |
| 4 | `RuleMode::Decision` turns only a 422 into `OnFalse`; a 404 or 409 from the node stays a reject | `Node/ApplyRuleMode.php:35` |
| 5 | A rejected result drops its events; a run that ends in a reject reports none | `Events/CollectEvents.php:30` |
| 6 | `route()` with `null` data hands on the previous step's data, `ok([])` does not | `Outcome/PassThrough.php:30` |
| 7 | An open transaction + connection without `SavepointInterface` -> `ConfigurationException` (500) | `Transaction/Bracket.php:84` |
| 8 | `validate(Class)` never instantiates the class; the injected `ValidatorInterface` does the check | `Start/ValidateInput.php:35` |
| 9 | The runner never calls `CheckRequires`; the generated handler does, before `run()` | `Start/CheckRequires.php:10-12` ("the runner never does") |
| 10 | A `TransientConflictException` in a nested run is rethrown; only the owning run answers 409 | `Failure/MapThrowable.php:48` |
| 11 | The loop limit is checked before the node is built; the exceeding step never runs | `Loop/GuardLoop.php:29` |
| 12 | After `build()` every mutator throws; a context class must extend `ProcessContext` | `ProcessDefinition.php:343`, `Context/CreateContext.php:24` |
| 13 | `withEvents()` replaces earlier events of the same result | `NodeResult.php:74` |
| 14 | A transactional process (the default) on a runner without connection (`forKernel` on a kernel without database) -> `ConfigurationException` (500, `process.connectionMissing`); opt out with `transactional(false)` | `Transaction/Bracket.php` (`CONNECTION_MISSING_KEY`) |
| 15 | `requires('dbConnection')` is not needed for the transaction - `forKernel()` resolves the writer itself; it only matters when a node uses the connection | `Start/ResolveConnection.php` |
| 16 | `declaredMessageKey()` is set only by a node's `reject()`; start, actor and thrown failures carry none, so their `data` has no `messageKey` (the 422 form carries its own) | `Handler/Response/BuildResponseData.php:44-47`, `Handler/Failure/BuildFailureOutcome.php:41` |
| 17 | `input(property)` prefixes only the `fields` of a 400 reject of its node; a reject without `fields` or with another status stays unchanged | `Handler/Outcome/BuildOutcome.php:66` |

## Layer rules

- Application layer builds definitions and starts the runner; the domain core never imports workflow classes
- Nodes stay thin and delegate to domain services; they neither publish nor dispatch
- Integration tests over SQLite with the real validator; mock ports only
- Generated process classes are smoke-tested against the working tree: `tests/Smoke` (builder golden copies + stubs, namespaces mapped in `tests/bootstrap.php`, no consumer `composer.json`, no path repo); `make phpstan-smoke` analyses it, generat findings are listed in `phpstan-smoke.neon`
