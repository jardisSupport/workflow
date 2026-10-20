---
name: support-workflow
description: jardissupport/workflow 4.0 - the process engine for one request-response use case, and nothing around it. Declare a node graph with ProcessDefinition, run it with ProcessRunner (no constructor), get a typed ProcessOutcome; NodeResult (ok, route, reject, withEvents), ProcessContext, answer form Answer (LastNode, Merged), MapResponse for call and sub-process nodes, reject outcome (status, messageKey, context, errors), loops. Validation, actor check, transaction, failure forms, envelope data and logging are the door's business (jardissupport/response). Use when working with ProcessRunner, ProcessDefinition, NodeResult, ProcessContext, Answer, MapResponse or jardissupport/workflow.
user-invocable: false
zone: post-active
persona: C
prerequisites: [foundation-architecture, foundation-patterns]
next: [generated-code-workflow-api, support-response]
---

# WORKFLOW_COMPONENT_SKILL
> `jardissupport/workflow` 4.0 | NS: `JardisSupport\Workflow` | PHP 8.3+ | requires `jardissupport/contracts` ^2.12 only

## Usage essentials

**Trigger:** ProcessRunner, ProcessDefinition, NodeResult, ProcessContext, ProcessOutcome, Answer, Answer::Merged, MapResponse, RuleMode, LoopLimitExceededException, UnroutedStatusException.

```
ProcessRunner::run($definition, $input, $actor, $nodeFactory, int $depth = 0)
  core: CreateContext -> Workflow engine over the node chain -> BuildOutcome
  per node: GuardLoop -> CollectEvents -> HydrateResponse -> ApplyRuleMode -> your node
  -> ProcessOutcome { status, response, errors, events, messageKey, context }
```

No input check, no actor check, no transaction, no logging, no failure forms in the engine: they belong to the door in front of the runner (`jardissupport/response`, skill `support-response`; in generated code the handler). What a node throws leaves `run()` unchanged.

| Concept | Meaning |
|---------|---------|
| Node | `callable(ProcessContext): NodeResult`; kinds Action (dev code), Call (command/query/sub-process), Rule (check) |
| Node factory | `\Closure(class-string): object` (only constructs); asked each time a node is due (also per loop pass); the package checks the object is callable, else `ConstructionException` |
| Route | `Route::OnTrue/OnFalse/OnSkip/OnTimeout/OnCancel/OnExit`; no data on the edge |
| Reject | `NodeResult::reject(4xx, ...)`: ends the run, the outcome carries status, messageKey, context, errors. Not a route |
| End | `terminal()` = a route mapped to `null` |
| Answer | `answer(Answer::LastNode)` (default) = data of the last step, `answer(Answer::Merged)` = data of all steps united |

```php
$definition = ProcessDefinition::for(PlaceOrder::class, 'PlaceOrder')   // input class, name
    ->context(CheckoutContext::class)->answer(Answer::Merged)
    ->node(CheckCredit::class)->onTrue(ReserveStock::class)->rejects(ResponseStatus::RuleViolation, 'order.creditExceeded')
    ->node(ReserveStock::class)->onTrue(ConfirmOrder::class)
    ->node(ConfirmOrder::class)->terminal()
    ->build();

$runner = new ProcessRunner();                                    // no constructor, no state
$outcome = $runner->run($definition, new PlaceOrder('ACME', 2), new Buyer('b-1'), $nodeFactory);
$outcome->status();   // ResponseStatus::Success
$outcome->response(); // object: all steps united (Answer::Merged)
$outcome->events();   // the door publishes these AFTER the commit
```

### API (`src/`)

| Class | Entry points |
|-------|--------------|
| `ProcessRunner` | `final`, no constructor; `run(definition, input, actor, nodeFactory, int $depth = 0): ProcessOutcome` (`depth` = depth of the calling run + 1 for a sub-process) |
| `ProcessDefinition` (`Data/ProcessDefinition.php`) | `for(inputClass, name)`, `context`, `answer(Answer)` (getter `getAnswer()`), `loopLimit(int)` (default 100), `ruleMode(node, RuleMode)`, `node`, `input(property)` (getter `getInput(node)`), `onTrue/onFalse/onSkip/onTimeout/onCancel/onExit(?target)`, `terminal(Route ...)`, `rejects(ResponseStatus, messageKey)`, `response(class, fields...)`, `answerResponse(class, fields...)` (getters `getAnswerResponseClass()`/`getAnswerResponse()`), `build()`; declarations only the door reads: `transactional(bool)`, `validate`, `actor`, `requires(...)` |
| `Answer` (enum) | `LastNode` (`'lastNode'`), `Merged` (`'merged'`) |
| `NodeResult` | `ok(array\|object)`, `route(Route, data = null)`, `reject(status, messageKey, context = [], errors = [])`, `withEvents(object ...)`; readers `takenRoute data isRejected status messageKey context errors events` |
| `ProcessContext` (not final) | `input actor previous latest(node) all(node) iteration(node) chain depth` |
| `ProcessOutcome` | `status response errors events messageKey context` |
| `MapResponse` | `__invoke(DomainResponseInterface, ?Route $onReject = null): NodeResult` (2xx ok + the Domain events of the callee flattened (Internal events stay behind); 4xx reject with the callee's plain context - `@type` dropped, inner `context` lifted, 400 `fields` / 409 `reason aggregate violated` / 422 `rule` kept; callee key only for 422; other 4xx e.g. 401/403: data unchanged as context; `$onReject` routes a 4xx instead; else throws: a 5xx a `CalleeFailureException` carrying the marker `ReportedFailureExceptionInterface`, any other status a plain `\RuntimeException`) |

## Full reference

**Context inheritance (W4).** `ProcessContext` is not `final`: generated processes extend it with typed getters over `latest()`/`all()`; named by `context()`, otherwise `run()` throws `ConstructionException` (`CreateContext.php:23`). The chain is immutable (`withStep` is engine-internal). Everything else in the package is `final`.

**Answer of a run.** `LastNode`: the data of the last step; a last step without data answers an empty object; nothing is handed on from earlier steps (read them with `latest($node)` / `all($node)`). `Merged`: the data of all executed steps in run order (loop passes included), the later step wins on the same field name; `null` and `[]` add no field, an object adds its public properties (`get_object_vars`), a non-empty list throws `ConstructionException` (`Handler/Outcome/BuildOutcome.php`, `merged`). With `answerResponse(class, fields...)` (only `Merged`, else `build()` throws) the union is projected onto the constructor fields of the class (none named = all; others fall away; missing required field or unknown class -> `ConstructionException`) - independent of kind and data of the last node; a node `response()` class on a node that ends the run is then not applied (`Context/HydrateResponse.php`, `Context/ConstructResponse.php`). A reject is unaffected by the answer form.

**Reject in the outcome.** Status = the 4xx, empty `response()`, `errors()` of the reject, no events, `messageKey()` = the key the node declared with `rejects(status, key)`, else the one the reject names, `context()` = the reject context. With `input(property)` on the node, a 400 reject prefixes its `fields[].field` with `<property>.`; other statuses unchanged (`BuildOutcome.php:45-46`, `prefixed`). The failure form and the wire envelope are built by the door.

**What the engine throws.** `ConstructionException` (not callable node, bad context class, unhydratable response, merged list, built definition changed), `UnroutedStatusException` (route without declared key), `LoopLimitExceededException` (`getLimit()`), `CalleeFailureException` from `MapResponse` on a 5xx (a `\RuntimeException` carrying the marker `ReportedFailureExceptionInterface`: the cause was already reported by the inner door, so the door does not log it again), a plain `\RuntimeException` on any other status without a node meaning; a node's own exceptions pass through. The engine answers no 500 and no 409: the door maps the throwables.

**Sub-process.** The engine knows none: a node runs the inner process with `depth + 1` through the inner door and hands the `DomainResponseInterface` it answers to `(new MapResponse())($answer, $onReject)`: events travel up, an inner 4xx is rejected again with the same status or routed with `$onReject`.

**Rule modes.** `Guard` (default): a 422 reject ends the run. `Decision`: a 422 becomes `Route::OnFalse`, every other result unchanged (`ApplyRuleMode.php:33`).

**Events.** On the `NodeResult`; collected in execution order; discarded for a rejected result and a rejected run; never dispatched by the package. Publish after commit (the door's commit).

**Loops.** Routes may point back; leave via an explicit route (`onExit`). `iteration(node)` counts passes. Step number `loopLimit + 1` never executes -> `LoopLimitExceededException`.

## Pitfalls

| # | Pitfall | Evidence |
|---|---------|----------|
| 1 | A route without a declared key throws `UnroutedStatusException`. Declare every route a node can take, `terminal()` or `onX(null)` for a deliberate end | `Routing/Workflow.php:49` |
| 2 | `reject()` is not a route and takes only 4xx; any other status throws `ConstructionException` | `NodeResult.php:62-63` |
| 3 | `response(class)` needs a value for every required constructor parameter named by the fields (none named = all); missing value or unknown class -> `ConstructionException` | `Context/HydrateResponse.php:54,68` |
| 4 | `RuleMode::Decision` turns only a 422 into `OnFalse`; a 404 or 409 from the node stays a reject | `Node/ApplyRuleMode.php:33` |
| 5 | A rejected result drops its events; a run that ends in a reject reports none | `Events/CollectEvents.php:30` |
| 6 | Nothing is handed on between nodes: `route()` without data is a result without data; with `Answer::LastNode` a last node without data answers an empty object; read earlier answers with `latest($node)` / `all($node)` or declare `Answer::Merged` | `Outcome/BuildOutcome.php` (`response`) |
| 7 | `Answer::Merged` needs fields by name: a non-empty list as step data throws `ConstructionException`; `null` and `[]` are fine | `Outcome/BuildOutcome.php` (`merged`) |
| 8 | `transactional`, `validate`, `actor`, `requires` are declarations for the door; the engine ignores them | `Data/ProcessDefinition.php` |
| 9 | The loop limit is checked before the node is built; the exceeding step never runs and `run()` throws | `Loop/GuardLoop.php:30` |
| 10 | After `build()` every mutator (also `answer()`) throws; a context class must extend `ProcessContext` | `ProcessDefinition.php:383-389`, `Context/CreateContext.php:23` |
| 11 | `withEvents()` replaces earlier events of the same result | `NodeResult.php:74` |
| 12 | `MapResponse` carries a callee message key only for 422 (400/404/409: only the key declared with `rejects()` reaches the outcome); a 5xx throws a `CalleeFailureException` (marker: cause already reported) even with `$onReject` | `Handler/Node/MapResponse.php` |
| 13 | `input(property)` prefixes only the `fields` of a 400 reject of its node; a reject without `fields` or with another status stays unchanged | `Handler/Outcome/BuildOutcome.php:85` |
| 14 | `answerResponse()` only with `Answer::Merged` (checked at `build()`, any call order); it types the PROCESS answer, `response()` types one NODE; mixing them up types the wrong thing | `Data/ProcessDefinition.php` (`build`), `Outcome/BuildOutcome.php` (`mergedData`) |

## Layer rules

- Application layer builds definitions and starts the runner; the domain core never imports workflow classes
- Nodes stay thin and delegate to domain services; they neither publish nor dispatch
- The engine opens no transaction, checks no input and logs nothing; the door owns that
- Integration tests with the real runner (`new ProcessRunner()`); mock ports only
