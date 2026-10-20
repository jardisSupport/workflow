# Jardis Workflow

![Build Status](https://github.com/jardisSupport/workflow/actions/workflows/ci.yml/badge.svg)
[![License: MIT](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE.md)
[![PHP Version](https://img.shields.io/badge/PHP-%3E%3D8.3-777BB4.svg)](https://www.php.net/)
[![PHPStan Level](https://img.shields.io/badge/PHPStan-Level%208-brightgreen.svg)](phpstan.neon)
[![PSR-12](https://img.shields.io/badge/Code%20Style-PSR--12-blue.svg)](phpcs.xml)
[![Coverage](https://img.shields.io/badge/Coverage-100.00%25-brightgreen.svg)](https://github.com/jardisSupport/workflow)

> Part of **[Jardis](https://jardis.io)** — the Domain-Driven Design platform for PHP. You model your domain; Jardis generates the production-ready hexagonal code (DTOs, Command/Query handlers, repositories, persistence). This package is part of the open-source foundation that generated code runs on.

Process engine for one request-response use case in PHP. A process is a declared graph of nodes; the runner walks the graph, collects the events and answers with one typed `ProcessOutcome` — status, response object, errors, events. The engine is the graph and nothing around it: input validation, actor check, transaction, failure forms and logging belong to the door in front of the runner (see `jardissupport/response`).

---

## Features

- **One run, one call** — `ProcessRunner::run()` takes input and actor and answers with a `ProcessOutcome`; a node that rejects ends the run with a 4xx, a node that throws is thrown on unchanged
- **Declarative graph** — `ProcessDefinition` describes nodes, routes (`onTrue`/`onFalse`/`onSkip`/`onTimeout`/`onCancel`/`onExit`), terminal ends, loop limit and per-node declarations; frozen by `build()`
- **Three node kinds** — Action (your code), Call (a command, query or sub-process), Rule (a business check); all share one contract: `callable(ProcessContext): NodeResult`
- **Reject instead of route** — `NodeResult::reject()` ends the run at once with a 4xx; it is not a route
- **Two answer forms** — `Answer::LastNode` (default: the data of the last step) or `Answer::Merged` (the data of all steps united), declared per process
- **Typed answers** — every node can declare a response class
- **Events at the result** — events travel with the `NodeResult`; the caller publishes them after the commit
- **Immutable chain** — `ProcessContext` exposes input, actor and the results of the nodes run so far
- **Strict routing** — a route without a declared key raises `UnroutedStatusException`; an explicit `null` target is a declared end
- **No door inside** — no validation, no transaction, no logging, no failure forms: what a node throws leaves the runner unchanged

---

## Installation

```bash
composer require jardissupport/workflow
```

Requires PHP 8.3+ and `jardissupport/contracts` ^2.12 (`ResponseStatus`, `DomainResponseInterface`). Nothing else.

---

## Concepts

| Concept | Meaning |
|---------|---------|
| Process | One run in one call. Declared by a `ProcessDefinition`, run by a `ProcessRunner` |
| Node | A step of the graph. **Action**: your code. **Call**: a command, query, sub-process or cross-context call. **Rule**: a business check |
| Route | `Route::OnTrue` / `OnFalse` (yes/no), `OnSkip`, `OnTimeout`, `OnCancel`, `OnExit`. Routes carry no data |
| Reject | `NodeResult::reject(4xx, ...)`. Not a route: the run ends and the outcome carries the status, the message key, the context and the errors of the reject |
| End | Declared with `terminal()` (a route mapped to `null`) |
| Answer | The form of the response of a successful run, declared with `answer(Answer)`: the last step or all steps united |
| Door | Everything in front of and around the engine: input check, actor check, transaction, failure forms, logging. Not part of this package |

---

## Quick Start

All examples below run top to bottom as one script; classes are shown without a namespace.

```php
use JardisSupport\Contract\Kernel\ResponseStatus;
use JardisSupport\Workflow\Data\Answer;
use JardisSupport\Workflow\Data\NodeResult;
use JardisSupport\Workflow\Data\ProcessContext;
use JardisSupport\Workflow\Data\ProcessDefinition;
use JardisSupport\Workflow\Data\Route;
use JardisSupport\Workflow\Data\RuleMode;
use JardisSupport\Workflow\ProcessRunner;

// Input, actor and event of the process
final class PlaceOrder
{
    public function __construct(public ?string $customer, public int $qty)
    {
    }
}

final class Buyer
{
    public function __construct(public string $id)
    {
    }
}

final class OrderPlaced
{
    public function __construct(public string $orderNo)
    {
    }
}
```

### Nodes

A node is any `callable(ProcessContext): NodeResult`. The runner asks the node factory for it when the node is due, by the name the definition declares (in generated code a class name). The factory is `\Closure(class-string): object` - it only constructs; the package checks that the object is callable and raises a `ConstructionException` naming its class when it is not.

```php
// Rule node: a check that rejects with 422 or lets the run go on
final class CheckCredit
{
    public function __invoke(ProcessContext $context): NodeResult
    {
        return $context->input()->qty > 100
            ? NodeResult::reject(ResponseStatus::RuleViolation, 'order.creditExceeded', ['rule' => 'CreditLimit'])
            : NodeResult::route(Route::OnTrue); // no data: this node adds nothing to the answer
    }
}

// The typed context: generated processes extend ProcessContext with typed getters
final class CheckoutContext extends ProcessContext
{
    public function reservedSku(): ?string
    {
        $data = $this->latest(ReserveStock::class)?->data();

        return is_array($data) ? $data['sku'] : null;
    }
}

// Action node: your code, here one write into a store
final class ReserveStock
{
    public function __construct(private ArrayObject $store)
    {
    }

    public function __invoke(ProcessContext $context): NodeResult
    {
        $qty = $context->input()->qty;
        $this->store->append(['sku' => 'A-1', 'qty' => $qty]);

        return NodeResult::ok(['sku' => 'A-1', 'qty' => $qty]);
    }
}

// The last node: answers and attaches an event
final class ConfirmOrder
{
    public function __invoke(CheckoutContext $context): NodeResult
    {
        $orderNo = 'O-' . $context->reservedSku();

        return NodeResult::ok(['orderNo' => $orderNo, 'status' => 'confirmed'])
            ->withEvents(new OrderPlaced($orderNo));
    }
}
```

### Definition

```php
$definition = ProcessDefinition::for(PlaceOrder::class, 'PlaceOrder')
    ->context(CheckoutContext::class)
    ->answer(Answer::Merged)
    ->node(CheckCredit::class)->onTrue(ReserveStock::class)
        ->rejects(ResponseStatus::RuleViolation, 'order.creditExceeded')
    ->node(ReserveStock::class)->onTrue(ConfirmOrder::class)
    ->node(ConfirmOrder::class)->terminal()
    ->build();
```

The first `node()` is the entry node. `onTrue(...)` and friends, `terminal()`, `rejects()`, `input()` and `response()` apply to the node declared last. After `build()` every mutator throws a `ConstructionException`.

### Run

```php
$store = new ArrayObject();
$runner = new ProcessRunner();

$nodeFactory = static fn(string $class): object => match ($class) {
    CheckCredit::class => new CheckCredit(),
    ReserveStock::class => new ReserveStock($store),
    ConfirmOrder::class => new ConfirmOrder(),
};

$outcome = $runner->run($definition, new PlaceOrder('ACME', 2), new Buyer('b-1'), $nodeFactory);

$outcome->status();   // ResponseStatus::Success (200)
$outcome->response(); // {sku: 'A-1', qty: 2, orderNo: 'O-A-1', status: 'confirmed'} - all steps united (Answer::Merged)
$outcome->events();   // [OrderPlaced {orderNo: 'O-A-1'}]

$rejected = $runner->run($definition, new PlaceOrder('ACME', 500), new Buyer('b-1'), $nodeFactory);

$rejected->status();     // ResponseStatus::RuleViolation (422)
$rejected->messageKey(); // 'order.creditExceeded'
$rejected->context();    // ['rule' => 'CreditLimit']
$rejected->response();   // empty object, the failure form is the door's business
```

---

## Definition API

`ProcessDefinition` (`src/Data/ProcessDefinition.php`), all methods fluent unless noted:

| Method | Effect |
|--------|--------|
| `ProcessDefinition::for(string $inputClass, string $name)` | Start a definition (static); empty arguments throw `ConstructionException` |
| `context(string $contextClass)` | Context class of the run, must extend `ProcessContext` (default: `ProcessContext`) |
| `answer(Answer $answer)` | Form of the response of a successful run: `Answer::LastNode` (default) or `Answer::Merged` |
| `loopLimit(int $loopLimit)` | Maximum node steps of one run (default `ProcessDefinition::DEFAULT_LOOP_LIMIT` = 100, minimum 1) |
| `ruleMode(string $node, RuleMode $mode)` | `RuleMode::Guard` or `RuleMode::Decision` for a rule node |
| `node(string $node)` | Declare a node and make it the current one |
| `onTrue` / `onFalse` / `onSkip` / `onTimeout` / `onCancel` / `onExit` `(?string $target)` | Route of the current node; `null` declares a terminal end |
| `terminal(Route ...$routes)` | Terminal end for the given routes (none given: `onTrue`) |
| `rejects(ResponseStatus $status, string $messageKey)` | Declare a 4xx the current node may reject with and the message key it answers for that status (non-4xx throws) |
| `input(string $property)` | Name the process input property that feeds the current node; its 400 rejects answer field paths below it |
| `response(string $responseClass, string ...$fields)` | Typed answer of the current node, see below |
| `answerResponse(string $responseClass, string ...$fields)` | Typed answer of the whole process, only with `Answer::Merged`, see "Answer of a run" |
| `build()` | Validate the graph (unknown targets, unknown rule-mode node) and freeze |

Declarations for the door, which reads them and acts on them - the engine itself ignores them: `transactional(bool)` (default on), `validate(string $validatorClass)`, `actor(string $actorClass)`, `requires(string ...$requires)`.

Getters: `getInputClass()`, `getName()`, `getContextClass()`, `getAnswer()`, `isTransactional()`, `getValidatorClass()`, `getActorClass()`, `getRequires()`, `getLoopLimit()`, `getRuleMode($node)`, `getRejects($node)`, `getInput($node)`, `getResponse($node)`, `getResponseClass($node)`, `getAnswerResponseClass()`, `getAnswerResponse()`, `isBuilt()`.

**`response(class, fields...)`**: the data array of the node is turned into an object of `class` through its constructor, with named arguments. The fields name the subset of constructor parameters taken from the data (none named: all). A required parameter without a value, or an unknown class, throws a `ConstructionException`. Only an array result of a routed node is converted; a node without a declaration keeps its data.

**Routing is strict.** A node that returns a route the definition has no key for throws `UnroutedStatusException`. `terminal()` and `onX(null)` declare the end on purpose.

## Runner

```text
new ProcessRunner()      // no constructor arguments, no state between runs

run(
    ProcessDefinition $definition,
    object $input,
    ?object $actor,
    \Closure $nodeFactory,   // \Closure(class-string): object - builds the node; callable-ness is checked by the package
    int $depth = 0           // nesting depth: 0 at the outermost level, else the depth of the calling run plus one
): ProcessOutcome
```

The node factory is called each time a node is due, also on every loop pass. Around each node: `GuardLoop`, `CollectEvents`, `HydrateResponse`, `ApplyRuleMode`. The runner does not check the input, resolve the actor, open a transaction or log: the engine answers a finished run only - 200, or the 4xx a node rejected with. What a node throws, and the exceptions of the engine itself (`ConstructionException`, `UnroutedStatusException`, `LoopLimitExceededException`), leave `run()` unchanged; the door maps them.

`ProcessOutcome` (`src/Data/ProcessOutcome.php`): `status(): ResponseStatus`, `response(): object`, `errors(): list<string>`, `events(): list<object>`, `messageKey(): ?string` and `context(): array` (both carry the reject of a failed run) and `isFailed(): bool` (status 400 and above). `ProcessOutcome` implements `JardisSupport\Contract\Kernel\AnswerableOutcomeInterface` (an `OutcomeInterface` - `status()`, `isFailed()` - plus the five accessors above), so a consumer such as the transaction bracket of `jardissupport/repository` recognises a failed run, and the answer closures of `jardissupport/response` build the answer from it, without knowing this package and without comparing status numbers. Existing methods are unchanged.

---

## ProcessContext

Read-only view of one run, handed to every node:

| Method | Returns |
|--------|---------|
| `input()` | The input object |
| `actor()` | The actor object or `null` |
| `previous()` | `?NodeResult` of the node run last |
| `latest(string $node)` | `?NodeResult`, most recent result of that node |
| `all(string $node)` | `list<NodeResult>` of that node in execution order |
| `iteration(string $node)` | How often that node has run so far (0 on the first pass) |
| `chain()` | `list<array{node, result}>`, the whole chain in order |
| `depth()` | Nesting depth of the run (0 = outermost) |

The chain is immutable: the engine hands every step a new context (`withStep()`, internal).

**Inheritance is a documented exception.** `ProcessContext` is deliberately not `final`: a generated process extends it with typed getters over the chain (see `CheckoutContext` above) and names it with `context()`. The class must extend `ProcessContext`, otherwise the run throws a `ConstructionException`. This is the one exception to composition over inheritance in the package; everything else is `final`.

## NodeResult

`NodeResult` (`src/Data/NodeResult.php`) is immutable:

| Factory | Meaning |
|---------|---------|
| `NodeResult::ok(array\|object $data)` | Success, takes `Route::OnTrue` with data |
| `NodeResult::route(Route $route, mixed $data = null)` | Take a named route; `null` data means a result without data - nothing is handed on from the previous step |
| `NodeResult::reject(ResponseStatus $status, string $messageKey, array $context = [], array $errors = [])` | End the run with a 4xx; any other status throws `ConstructionException` |
| `->withEvents(object ...$events)` | New result carrying the events (replaces earlier ones) |

Readers: `takenRoute()` (null exactly for a reject), `data()`, `isRejected()`, `status()`, `messageKey()`, `context()`, `errors()`, `events()`.

**Call nodes.** `MapResponse` turns the answer of a called domain operation (a `DomainResponseInterface` - an aggregate command, or the inner door of a sub-process) into a node result: 2xx becomes `ok(data)` carrying the Domain events of the callee (the contract keys them by scope, they travel on flattened; Internal events stay behind - they are the write gesture of the callee's aggregate), 4xx a `reject` with the same status whose context is the *plain* context of the callee, every other status throws: a 5xx a `CalleeFailureException` (a `\RuntimeException` carrying the marker `ReportedFailureExceptionInterface` - the inner door has already reported the cause, so the outer door does not log it again), any other status without a node meaning (302, 418) a plain `\RuntimeException`. The form of the callee's data is unpacked, so the process answers the same form again (no form inside a form):

| Callee status | Reject context | Reject `messageKey` |
|---------------|----------------|---------------------|
| 400 | `fields` | `''` |
| 404 | the inner `context` | `''` |
| 409 | `reason`, `aggregate`, `violated`, plus the inner `context` | `''` |
| 422 | `rule`, plus the inner `context` | the callee's `messageKey` |
| other 4xx (401, 403, ...) | the data unchanged, a `messageKey` in it stays where it is | `''` |

`@type` is dropped. The message key travels as the reject key only for 422; for 400, 404 and 409 the key is left to the node's declaration (`rejects()`), and a status without a form passes its data through unchanged. The form's own fields win over an inner context key of the same name. With `$onReject` (`(new MapResponse())($response, Route::OnFalse)`) a 4xx becomes a route instead: the dev decision to carry on rather than refuse; a 5xx throws either way, as a `CalleeFailureException`.

```php
$result = (new MapResponse())($notFoundResponse); // 404 with data ['@type' => 'notFound', 'context' => ['sku' => 'Z-9']]
$result->isRejected(); // true
$result->status();     // ResponseStatus::NotFound
$result->messageKey(); // ''
$result->context();    // ['sku' => 'Z-9']
```

### Sub-process nodes

A node runs an inner process one level deeper and maps the answer of the inner **door** - a `DomainResponseInterface` - with `MapResponse`: events travel up, an inner 4xx is rejected again with the same status, or routed with `$onReject`. The engine knows no sub-process: the inner run is a `run()` with `depth + 1`, the door around it builds the answer.

```php
final class ReleaseBatch
{
    public function __construct(private InnerDoor $innerDoor)
    {
    }

    public function __invoke(ProcessContext $context): NodeResult
    {
        // $innerDoor runs the inner process with $context->depth() + 1 and answers its DomainResponseInterface
        return (new MapResponse())($this->innerDoor->answer($context), Route::OnFalse);
    }
}
```

### Rule nodes

A rule node is a normal node that rejects with `ResponseStatus::RuleViolation` (422) or goes on. `ruleMode()` decides how the process takes a violation:

- `RuleMode::Guard` (default): the reject ends the run, the outcome carries 422
- `RuleMode::Decision`: a 422 becomes `Route::OnFalse` and the process goes on there; every other result passes unchanged

```php
final class ReviewOrder
{
    public function __invoke(ProcessContext $context): NodeResult
    {
        return NodeResult::ok(['status' => 'review']);
    }
}

$decision = ProcessDefinition::for(PlaceOrder::class, 'PlaceOrderReviewed')
    ->ruleMode(CheckCredit::class, RuleMode::Decision)
    ->node(CheckCredit::class)->onTrue(ReserveStock::class)->onFalse(ReviewOrder::class)
    ->node(ReserveStock::class)->terminal()
    ->node(ReviewOrder::class)->terminal()
    ->build();

$outcome = $runner->run($decision, new PlaceOrder('ACME', 500), null, static fn(string $class): object => match ($class) {
    CheckCredit::class => new CheckCredit(),
    ReserveStock::class => new ReserveStock($store),
    ReviewOrder::class => new ReviewOrder(),
});
// ResponseStatus::Success, response {status: 'review'}
```

---

## Answer of a run

The form of the response of a successful run is declared per process with `answer(Answer)`; the status is 200 and `errors()` is empty.

| `Answer` | Response |
|----------|----------|
| `LastNode` (default) | The data of the **last** step. A last step without data - an event node, a rule node that only lets the run go on (`NodeResult::route(Route::OnTrue)`) - answers an empty object. Nothing is handed on from earlier steps |
| `Merged` | The data of **all** executed steps united in run order; on the same field name the later step wins. Loop passes count as steps, in run order. A step without data (`null`, `[]`) contributes no field; an object contributes its public properties (`get_object_vars`); a non-empty list (`['a', 'b']`) throws a `ConstructionException` - the union needs fields by name |

```php
$merged = ProcessDefinition::for(stdClass::class, 'Merged')
    ->answer(Answer::Merged)
    ->node('Merged\Find')->onTrue('Merged\Reserve')
    ->node('Merged\Reserve')->terminal()
    ->build();

$outcome = $runner->run($merged, new stdClass(), null, static fn(string $class): object => match ($class) {
    'Merged\Find' => static fn(ProcessContext $c): NodeResult => NodeResult::ok(['id' => 7, 'state' => 'found']),
    'Merged\Reserve' => static fn(ProcessContext $c): NodeResult => NodeResult::ok(['state' => 'reserved']),
});
// response {id: 7, state: 'reserved'}
```

**Typed process answer.** `answerResponse(class, fields...)` (only with `Merged`; `build()` throws a `ConstructionException` for `LastNode`, in any call order) projects the union onto the constructor fields of `class` (none named: all; fields not named fall away). The answer then no longer depends on the kind or the data of the last node - a sub-process node that hands on a foreign answer, or a node without data, leaves the fields of the earlier steps intact. A required field the union lacks, or an unknown class, throws a `ConstructionException`. A `response()` class on a node that **ends** the run is not applied in this case (the process answer is not the class of its last node); the `response()` classes of the other nodes stay. Without `answerResponse()` the union is returned as it is.

```php
$definition = ProcessDefinition::for(CreateQuote::class, 'CreateQuote')
    ->answer(Answer::Merged)
    ->answerResponse(QuoteAnswer::class, 'quoteNumber', 'status')   // QuoteAnswer(string $quoteNumber, string $status)
    ->node(Create::class)->onTrue(PriceCheck::class)
    ->node(PriceCheck::class)->terminal()                           // a sub-process with a foreign answer
    ->build();
// response QuoteAnswer {quoteNumber, status}: later wins, everything else falls away
```

A node that needs the answer of an earlier node reads it explicitly through `ProcessContext::latest($node)` / `all($node)` (the typed getters of a generated context do exactly that); `previous()` is the result of the node run last, which can be a node without data. A reject is unaffected by the answer form: it ends the run with its status, message key, context and errors.

**Reject in the outcome.** A rejected run answers `status()` = the 4xx, an empty `response()`, `errors()` of the reject, no events, `messageKey()` and `context()`. The message key is the one the node declared for that status with `rejects(status, key)`, else the one the reject names. A 400 reject of a node fed by one input property (`input('customer')`) answers its field paths below that property (`customer.<field>`). Turning the outcome into a failure form (`ValidationFailure`, `NotFound`, `Conflict`, `RuleViolation`) is the business of the door.

## Events

Events travel with the node result (`->withEvents(...)`); the package never sends them anywhere.

- A successful run reports the events of all steps in execution order (`$outcome->events()`), including those a sub-process node passes up (`MapResponse` carries them).
- A rejected run reports no events.
- **The caller publishes after the commit.** The engine opens no transaction; the door commits, then publishes:

```php
$outcome = $runner->run($definition, new PlaceOrder('ACME', 2), new Buyer('b-1'), $nodeFactory);

$published = [];
foreach ($outcome->events() as $event) {
    $published[] = $event; // with a PSR-14 dispatcher: $dispatcher->dispatch($event);
}
```

## Loops

A route may point back to an earlier node. `iteration($node)` counts the passes; the way out is an explicit route (usually `onExit`). `loopLimit()` caps the node steps of one run (default 100); the step that would exceed the limit never executes, `run()` throws a `LoopLimitExceededException` (`getLimit()`). The engine answers no 500: the door maps the exception. A sub-process counts its steps on its own.

```php
final class Poll
{
    public function __invoke(ProcessContext $context): NodeResult
    {
        $pass = $context->iteration(self::class) + 1;

        return $pass >= 3
            ? NodeResult::route(Route::OnExit, ['polls' => $pass])
            : NodeResult::route(Route::OnFalse, ['polls' => $pass]);
    }
}

$poll = static fn(int $limit): ProcessDefinition => ProcessDefinition::for(stdClass::class, 'Poll')
    ->loopLimit($limit)
    ->node(Poll::class)->onFalse(Poll::class)->onExit(null)
    ->build();
$pollFactory = static fn(string $class): object => new Poll();

$outcome = $runner->run($poll(10), new stdClass(), null, $pollFactory);
// ResponseStatus::Success, response {polls: 3}

$runner->run($poll(2), new stdClass(), null, $pollFactory);
// throws LoopLimitExceededException
```

---

## Layer rules

- The application layer builds the definition and starts the runner; the domain core never imports workflow classes.
- Nodes stay thin and delegate to domain services. A node does not publish events and does not dispatch.
- The package publishes nothing, dispatches nothing and opens no transaction; the door owns validation, transaction, failure forms, logging and the events of the outcome.

## Documentation

Full documentation, guides, and API reference:

**[docs.jardis.io/en/support/workflow](https://docs.jardis.io/en/support/workflow)**

## License

This package is licensed under the [MIT License](LICENSE.md).

---

**[Jardis](https://jardis.io)** · [Documentation](https://docs.jardis.io) · [Headgent](https://headgent.com)

<!-- BEGIN jardis/dev-skills README block — do not edit by hand -->
## AI-Assisted Development

This package ships with a skill for Claude Code, Cursor, Continue, and Aider. Install it in your consuming project:

```bash
composer require --dev jardis/dev-skills
```

More details: <https://docs.jardis.io/en/skills>
<!-- END jardis/dev-skills README block -->
