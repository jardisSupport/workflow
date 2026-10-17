# Jardis Workflow

![Build Status](https://github.com/jardisSupport/workflow/actions/workflows/ci.yml/badge.svg)
[![License: MIT](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE.md)
[![PHP Version](https://img.shields.io/badge/PHP-%3E%3D8.3-777BB4.svg)](https://www.php.net/)
[![PHPStan Level](https://img.shields.io/badge/PHPStan-Level%208-brightgreen.svg)](phpstan.neon)
[![PSR-12](https://img.shields.io/badge/Code%20Style-PSR--12-blue.svg)](phpcs.xml)
[![Coverage](https://img.shields.io/badge/Coverage-99.50%25-brightgreen.svg)](https://github.com/jardisSupport/workflow)

> Part of **[Jardis](https://jardis.io)** — the Domain-Driven Design platform for PHP. You model your domain; Jardis generates the production-ready hexagonal code (DTOs, Command/Query handlers, repositories, persistence). This package is part of the open-source foundation that generated code runs on.

Process engine for one request-response use case in PHP. A process is a declared graph of nodes; the runner validates the input, opens the transaction, walks the graph, collects the events and answers with one typed `ProcessOutcome` — status, response object, errors, events. Failures have fixed answer forms (`ValidationFailure`, `NotFound`, `Conflict`, `RuleViolation`).

---

## Features

- **One run, one call** — `ProcessRunner::run()` takes input and actor and answers with a `ProcessOutcome`; it never throws for a business failure
- **Declarative graph** — `ProcessDefinition` describes nodes, routes (`onTrue`/`onFalse`/`onSkip`/`onTimeout`/`onCancel`/`onExit`), terminal ends, loop limit and per-node declarations; frozen by `build()`
- **Three node kinds** — Action (your code), Call (a command, query or sub-process), Rule (a business check); all share one contract: `callable(ProcessContext): NodeResult`
- **Reject instead of route** — `NodeResult::reject()` ends the run at once with a 4xx; it is not a route
- **Typed answers** — every node can declare a response class; the answer of the last node is the answer of the run
- **Start layers** — input validation (400), actor check (401), required resources (`CheckRequires`)
- **Transaction bracket** — one transaction per run, savepoint `sp_{depth}` for nested runs, opt-out per process
- **Kernel entry** — `ProcessRunner::forKernel($kernel, $validator)` takes the writer connection and the logger from the kernel (pool, plain PDO or none; logger or none)
- **Cause is logged** — the throwable behind a 5xx answer goes to the PSR-3 logger once; nothing of it reaches the answer
- **Events at the result** — events travel with the `NodeResult`; the caller publishes them after the commit
- **Immutable chain** — `ProcessContext` exposes input, actor and the results of the nodes run so far
- **Strict routing** — a route without a declared key raises `UnroutedStatusException`; an explicit `null` target is a declared end
- **PSR-20 clock** — response metadata takes its time from an injected `ClockInterface`

---

## Installation

```bash
composer require jardissupport/workflow
```

Requires PHP 8.3+, `jardissupport/contracts` ^2.12, `jardissupport/repository` ^1.6 (`PdoConnection`, used by `ResolveConnection`) `jardissupport/scheduling` ^1.2 (clock implementations) and `psr/log` ^3.0 (the optional logger). The runner needs a `ValidatorInterface` (for example `ObjectValidator` from `jardissupport/validation`) and, for transactional processes, a `DbConnectionInterface` - or a `DomainKernelInterface` that provides one (`ProcessRunner::forKernel()`).

---

## Concepts

| Concept | Meaning |
|---------|---------|
| Process | One run in one call. Declared by a `ProcessDefinition`, run by a `ProcessRunner` |
| Start | Not a node. Checks the input (400), the actor (401) and opens the transaction |
| Node | A step of the graph. **Action**: your code. **Call**: a command, query, sub-process or cross-context call. **Rule**: a business check |
| Route | `Route::OnTrue` / `OnFalse` (yes/no), `OnSkip`, `OnTimeout`, `OnCancel`, `OnExit`. Routes carry no data |
| Reject | `NodeResult::reject(4xx, ...)`. Not a route: the run ends, the transaction rolls back, the answer is the failure form of the status |
| End | Declared with `terminal()` (a route mapped to `null`). The response of the last node is the response of the run |
| Response | Typed object per node, declared with `response(class, fields...)` |

---

## Quick Start

All examples below run top to bottom as one script; classes are shown without a namespace.

```php
use JardisSupport\Contract\Kernel\ResponseStatus;
use JardisSupport\Contract\Validation\ValidatorInterface;
use JardisSupport\Repository\Adapter\PdoConnection;
use JardisSupport\Scheduling\Clock\SystemClock;
use JardisSupport\Validation\CompositeFieldValidator;
use JardisSupport\Validation\ObjectValidator;
use JardisSupport\Validation\Validator\NotBlank;
use JardisSupport\Validation\Validator\Range;
use JardisSupport\Validation\ValidatorRegistry;
use JardisSupport\Workflow\Data\NodeResult;
use JardisSupport\Workflow\Data\ProcessContext;
use JardisSupport\Workflow\Data\ProcessDefinition;
use JardisSupport\Workflow\Data\ReadResult;
use JardisSupport\Workflow\Data\Reference;
use JardisSupport\Workflow\Data\Response\ContextResponse;
use JardisSupport\Workflow\Data\Response\DomainResponse;
use JardisSupport\Workflow\Data\Route;
use JardisSupport\Workflow\Data\RuleMode;
use JardisSupport\Workflow\Exception\LoopLimitExceededException;
use JardisSupport\Workflow\Handler\Node\MapResponse;
use JardisSupport\Workflow\Handler\Response\TransformResponse;
use JardisSupport\Workflow\ProcessRunner;

// Input, actor, typed answers and event of the process
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

final class Reserved
{
    public function __construct(public string $sku, public int $qty)
    {
    }
}

final class Confirmed
{
    public function __construct(public string $orderNo, public string $status)
    {
    }
}

final class OrderPlaced
{
    public function __construct(public string $orderNo)
    {
    }
}

// The validation set the definition names; the injected validator port does the checking
final class PlaceOrderRules
{
    public static function validator(): ValidatorInterface
    {
        $registry = (new ValidatorRegistry())->register(
            PlaceOrder::class,
            (new CompositeFieldValidator())
                ->field('customer')->validates(NotBlank::class)->end()
                ->field('qty')->validates(Range::class, Range::min(1))->end()
        );

        return new ObjectValidator($registry, fieldPaths: true);
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
            : NodeResult::route(Route::OnTrue); // no data: the data of the node before is handed on
    }
}

// The typed context: generated processes extend ProcessContext with typed getters
final class CheckoutContext extends ProcessContext
{
    public function reserved(): ?Reserved
    {
        $data = $this->latest(ReserveStock::class)?->data();

        return $data instanceof Reserved ? $data : null;
    }
}

// Action node: your code, here one write
final class ReserveStock
{
    public function __construct(private PDO $pdo)
    {
    }

    public function __invoke(ProcessContext $context): NodeResult
    {
        $qty = $context->input()->qty;
        $this->pdo->prepare('INSERT INTO reservation (sku, qty) VALUES (?, ?)')->execute(['A-1', $qty]);

        return NodeResult::ok(['sku' => 'A-1', 'qty' => $qty]);
    }
}

// The last node: answers and attaches an event
final class ConfirmOrder
{
    public function __invoke(CheckoutContext $context): NodeResult
    {
        $orderNo = 'O-' . $context->reserved()?->sku;

        return NodeResult::ok(['orderNo' => $orderNo, 'status' => 'confirmed'])
            ->withEvents(new OrderPlaced($orderNo));
    }
}
```

### Definition

```php
$definition = ProcessDefinition::for(PlaceOrder::class, 'PlaceOrder')
    ->validate(PlaceOrderRules::class)
    ->actor(Buyer::class)
    ->context(CheckoutContext::class)
    ->node(CheckCredit::class)->onTrue(ReserveStock::class)
        ->rejects(ResponseStatus::RuleViolation, 'order.creditExceeded')
    ->node(ReserveStock::class)->onTrue(ConfirmOrder::class)
        ->response(Reserved::class)
    ->node(ConfirmOrder::class)->terminal()
        ->response(Confirmed::class)
    ->build();
```

The first `node()` is the entry node. `onTrue(...)` and friends, `terminal()`, `rejects()`, `input()` and `response()` apply to the node declared last. After `build()` every mutator throws a `ConstructionException`.

### Run

```php
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE reservation (sku TEXT, qty INTEGER)');

$runner = new ProcessRunner(new PdoConnection($pdo), PlaceOrderRules::validator());

$nodeFactory = static fn(string $class): object => match ($class) {
    CheckCredit::class => new CheckCredit(),
    ReserveStock::class => new ReserveStock($pdo),
    ConfirmOrder::class => new ConfirmOrder(),
};

$outcome = $runner->run($definition, new PlaceOrder('ACME', 2), new Buyer('b-1'), $nodeFactory);

$outcome->status();   // ResponseStatus::Success (200)
$outcome->response(); // Confirmed {orderNo: 'O-A-1', status: 'confirmed'}
$outcome->events();   // [OrderPlaced {orderNo: 'O-A-1'}]
```

---

## Definition API

`ProcessDefinition` (`src/Data/ProcessDefinition.php`), all methods fluent unless noted:

| Method | Effect |
|--------|--------|
| `ProcessDefinition::for(string $inputClass, string $name)` | Start a definition (static); empty arguments throw `ConstructionException` |
| `context(string $contextClass)` | Context class of the run, must extend `ProcessContext` (default: `ProcessContext`) |
| `transactional(bool $transactional)` | Transaction bracket on (default) or off |
| `validate(string $validatorClass)` | The input is checked by the injected validator before any node |
| `actor(string $actorClass)` | The process needs an actor; without one the run ends with 401 |
| `requires(string ...$requires)` | Kernel resources the process needs (checked by `CheckRequires`) |
| `loopLimit(int $loopLimit)` | Maximum node steps of one run (default `ProcessDefinition::DEFAULT_LOOP_LIMIT` = 100, minimum 1) |
| `ruleMode(string $node, RuleMode $mode)` | `RuleMode::Guard` or `RuleMode::Decision` for a rule node |
| `node(string $node)` | Declare a node and make it the current one |
| `onTrue` / `onFalse` / `onSkip` / `onTimeout` / `onCancel` / `onExit` `(?string $target)` | Route of the current node; `null` declares a terminal end |
| `terminal(Route ...$routes)` | Terminal end for the given routes (none given: `onTrue`) |
| `rejects(ResponseStatus $status, string $messageKey)` | Declare a 4xx the current node may reject with and the message key it answers for that status (non-4xx throws) |
| `input(string $property)` | Name the process input property that feeds the current node; its 400 rejects answer field paths below it |
| `response(string $responseClass, string ...$fields)` | Typed answer of the current node, see below |
| `build()` | Validate the graph (unknown targets, unknown rule-mode node) and freeze |

Getters: `getInputClass()`, `getName()`, `getContextClass()`, `isTransactional()`, `getValidatorClass()`, `getActorClass()`, `getRequires()`, `getLoopLimit()`, `getRuleMode($node)`, `getRejects($node)`, `getResponse($node)`, `getResponseClass($node)`, `isBuilt()`.

**`response(class, fields...)`**: the data array of the node is turned into an object of `class` through its constructor, with named arguments. The fields name the subset of constructor parameters taken from the data (none named: all). A required parameter without a value, or an unknown class, is a `ConstructionException` (answer 500). Only an array result of a routed node is converted; a node without a declaration keeps its data.

**Routing is strict.** A node that returns a route the definition has no key for raises `UnroutedStatusException` (answer 500). `terminal()` and `onX(null)` declare the end on purpose.

## Runner

```text
new ProcessRunner(?DbConnectionInterface $connection, ValidatorInterface $validator, ?LoggerInterface $logger = null)
ProcessRunner::forKernel(DomainKernelInterface $kernel, ValidatorInterface $validator): ProcessRunner

run(
    ProcessDefinition $definition,
    object $input,
    ?object $actor,
    \Closure $nodeFactory,        // \Closure(class-string): object - builds the node; callable-ness is checked by the package
    ?ProcessContext $parent = null // the surrounding run, when this one is a sub-process
): ProcessOutcome
```

The runner is pure composition; it holds no state between runs. The node factory is called each time a node is due, also on every loop pass. Layers, outside in: `LogCause`, `MapThrowable`, `ValidateInput`, `CheckActor`, `Bracket`, then the core. Around each node: `GuardLoop`, `CollectEvents`, `HydrateResponse`, `PassThrough`, `ApplyRuleMode`.

**Connection.** The connection is optional. `forKernel()` also hands the kernel's `logger()` (PSR-3, may be none) to the runner. It resolves the connection with `ResolveConnection` from `$kernel->dbConnection()`: a `ConnectionPoolInterface` gives its `getWriter()`, a plain `PDO` is wrapped in `PdoConnection` (transactions and savepoints), no database gives `null`. A transactional process without a connection ends with 500 (`ConfigurationException`, message key `process.connectionMissing`); a process with `transactional(false)` runs without one.

```php
$runner = ProcessRunner::forKernel($kernel, $validator);   // same as new ProcessRunner((new ResolveConnection())($kernel), $validator)
```

`ProcessOutcome` (`src/Data/ProcessOutcome.php`): `status(): ResponseStatus`, `response(): object`, `errors(): list<string>`, `events(): list<object>`, `cause(): ?Throwable` (set for an unexpected failure only; a 5xx cause is also logged, see Answers and failures).

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

**Inheritance is a documented exception.** `ProcessContext` is deliberately not `final`: a generated process extends it with typed getters over the chain (see `CheckoutContext` above) and names it with `context()`. The class must extend `ProcessContext`, otherwise the run ends with 500 (`ConstructionException`). This is the one exception to composition over inheritance in the package; everything else is `final`.

## NodeResult

`NodeResult` (`src/Data/NodeResult.php`) is immutable:

| Factory | Meaning |
|---------|---------|
| `NodeResult::ok(array\|object $data)` | Success, takes `Route::OnTrue` with data |
| `NodeResult::route(Route $route, mixed $data = null)` | Take a named route; `null` data hands on the data of the previous step (`PassThrough`) |
| `NodeResult::reject(ResponseStatus $status, string $messageKey, array $context = [], array $errors = [])` | End the run with a 4xx; any other status throws `ConstructionException` |
| `->withEvents(object ...$events)` | New result carrying the events (replaces earlier ones) |

Readers: `takenRoute()` (null exactly for a reject), `data()`, `isRejected()`, `status()`, `messageKey()`, `context()`, `errors()`, `events()`.

**Call nodes.** `MapResponse` turns the answer of a called domain operation (`DomainResponseInterface`) into a node result: 2xx becomes `ok(data)`, 4xx a `reject` whose context is the *plain* context of the callee, every other status throws. The form of the callee's data is unpacked, so the process answers the same form again (no form inside a form):

| Callee status | Reject context | Reject `messageKey` |
|---------------|----------------|---------------------|
| 400 | `fields` | `''` |
| 404 | the inner `context` | `''` |
| 409 | `reason`, `aggregate`, `violated`, plus the inner `context` | `''` |
| 422 | `rule`, plus the inner `context` | the callee's `messageKey` |
| other 4xx (401, 403, ...) | the data unchanged, a `messageKey` in it stays where it is | `''` |

`@type` is dropped. The message key travels as the reject key only for 422; for 400, 404 and 409 the key is left to the node's declaration (`rejects()`, see "Message key in `data`"), and a status without a form passes its data through unchanged. The form's own fields win over an inner context key of the same name.

```php
$answer = new DomainResponse(ResponseStatus::NotFound, ['@type' => 'notFound', 'context' => ['sku' => 'Z-9']], [], [], []);
$result = (new MapResponse())($answer);
$result->isRejected(); // true
$result->status();     // ResponseStatus::NotFound
$result->messageKey(); // ''
$result->context();    // ['sku' => 'Z-9']
```

### Sub-process nodes

`MapOutcome` turns the `ProcessOutcome` of an inner run into the result of the node that called it, in one call: `(new MapOutcome())($outcome, ?Route $onReject = null, string $process = '')`.

| Inner outcome | Node result |
|---------------|-------------|
| 2xx | `ok((array) response)` carrying **all** events of the inner run |
| 4xx, `$onReject` given | `route($onReject)` - the dev decision to carry on |
| 4xx, no `$onReject` | `reject` with the **same status** and the inner failure form unpacked (like `MapResponse`: 400 `fields`, 409 `reason`/`aggregate`/`violated`, 422 `rule`, the rest as plain context), so the outer answer has the same wire form |
| 5xx | throws `cause()`; without a cause a `RuntimeException` naming `$process` and the status |

The message key follows the same rule as `MapResponse`: the key the inner node declared (for a 422 the key of its own form) travels, the keys of the package's own layers (`process.inputInvalid`, ...) never do. The errors of the inner run travel on. A 400 additionally gets the field paths below the `input()` property of the calling node, like any other reject of that node.

```php
$outcome = $runner->run($inner, $input, $context->actor(), $innerNodes, $context);

return (new MapOutcome())($outcome, onReject: null, process: 'NotifyMember');
```

### Rule nodes

A rule node is a normal node that rejects with `ResponseStatus::RuleViolation` (422) or goes on. `ruleMode()` decides how the process takes a violation:

- `RuleMode::Guard` (default): the reject ends the run, answer 422 `RuleViolation`
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
    ReserveStock::class => new ReserveStock($pdo),
    ReviewOrder::class => new ReviewOrder(),
});
// ResponseStatus::Success, response {status: 'review'}
```

---

## Start

The start is not a node. Before the first node, in this order:

| Layer | Condition | Answer |
|-------|-----------|--------|
| `ValidateInput` | the definition names a validator and the injected `ValidatorInterface` reports findings | 400, `ValidationFailure` with `fields[]` (`field`, `reason`, `message`, `messageKey`), message key `process.inputInvalid` |
| `CheckActor` | the definition names an actor class and the run got none | 401, message key `process.actorMissing` |
| `Bracket` | transaction on | the run opens its transaction (no connection: 500, `process.connectionMissing`) |

Nodes, context and transaction do not exist for a failed start. Whether the actor *may* do the thing (403) is a decision of the nodes, never of the start.

```php
$outcome = $runner->run($definition, new PlaceOrder(null, 0), new Buyer('b-1'), $nodeFactory);
// 400, response ValidationFailure; fields: [
//   ['field' => 'customer', 'reason' => 'missing', 'message' => '...', 'messageKey' => 'validation.missing'],
//   ['field' => 'qty', 'reason' => 'invalid', 'message' => '...', 'messageKey' => 'validation.invalid'],
// ]

$outcome = $runner->run($definition, new PlaceOrder('ACME', 1), null, $nodeFactory);
// 401, message key process.actorMissing
```

**The field findings of the 400.** The validator port delivers `field`, `reason` (`missing` | `invalid`) and `message` and leaves the answer vocabulary to the caller; the start (`Handler/Failure/DescribeFieldFinding`) adds it: every finding gets `messageKey` = `validation.missing` | `validation.invalid` from its `reason`, and a finding whose validator text is empty gets the standard text (`Field is required.` for `missing`, `Field is invalid.` otherwise). A text the validator delivered stays untouched; `errors()` lists only those delivered texts.

**Required resources.** `requires('auth', 'cache', ...)` names optional accessors of the `DomainKernelInterface` (`cache`, `logger`, `eventDispatcher`, `eventListenerRegistry`, `httpClient`, `dbConnection`, `mailer`, `filesystem`, `messaging`, `auth`). The generated handler, not the runner, calls the check before the run. `requires('dbConnection')` is only needed when a node itself uses the database connection: the runner resolves the connection for the transaction on its own (`forKernel()`), and a missing one is refused by the `Bracket` of a transactional process:

```text
(new CheckRequires())(DomainKernelInterface $kernel, array $requires): void
// throws ConfigurationException for an unknown name or an accessor that answers null
```

---

## Answers and failures

A run answers with a `ProcessOutcome`. Success is status 200, the response is the typed data of the last node, `errors()` is empty. Every failure goes through one place and has a fixed form:

| Status | Form (`Data\Failure\*`) | Built from context keys |
|--------|-------------------------|-------------------------|
| 400 | `ValidationFailure(messageKey, fields[], context)` | `fields`; without it each error text becomes one finding with an empty `field` |
| 404 | `NotFound(messageKey, context)` | all keys are context |
| 409 | `Conflict(messageKey, context, reason, aggregate, violated[])` | `reason`, `aggregate`, `violated` |
| 422 | `RuleViolation(rule, messageKey, context)` | `rule` |
| other 4xx | a reject of a node: `stdClass` of its context (flat data), its key joins the data last; the start failure without a form (401 actor missing): `stdClass {messageKey, context}`, internal only | as given |
| 5xx | empty `stdClass`; the throwable is `cause()` | nothing of it reaches the answer (it goes to the log, see below) |

All forms implement `FailureForm` (`status()`, `messageKey()`, `context()`). A failed run reports no events.

**Thrown failures** are mapped once, by the outermost layer `MapThrowable`:

| Thrown | Answer |
|--------|--------|
| `TransientConflictException` in the owning run | 409, message key `process.transientConflict`, context `{reason: 'stale_write', aggregate: <process name>}` |
| `TransientConflictException` in a nested run | thrown on; the owner repeats the unit of work |
| every other `Throwable` (construction, configuration, loop limit, unrouted route, ...) | 500, empty object, throwable as `cause()` |

Message keys of the package: `process.inputInvalid` (400), `process.actorMissing` (401), `process.transientConflict` (409); field findings of the 400 carry `validation.missing` / `validation.invalid`.

**The cause is logged.** A 5xx answer shows nothing of its throwable, so the outermost layer `LogCause` writes it to the logger the runner got (`new ProcessRunner($connection, $validator, $logger)`; `forKernel()` passes `$kernel->logger()`): `error(<throwable message>, ['exception' => <throwable>, 'process' => <process name>])`. Rules:

- once per cause, by the owning run only: a nested run (sub-process) does not log, the node of the surrounding run throws the cause on and the owner logs it;
- only for status 5xx: a reject (4xx) is a business outcome, and the 409 of a stale write is repeated by the caller - neither is logged;
- without a logger nothing is logged; a logger that throws never replaces the answer.

```php
$outcome = $runner->run($definition, new PlaceOrder('ACME', 500), new Buyer('b-1'), $nodeFactory);
// 422, response RuleViolation {rule: 'CreditLimit', messageKey: 'order.creditExceeded'}; nothing was written
```

**Data of the envelope.** `BuildResponseData` turns the outcome into the `data` array of the envelope, the one place the wire form of an answer is shaped. The generated handler sets it on the context response; `TransformResponse` then wraps it:

```php
$this->result()->setData((new BuildResponseData())($outcome, $definition->getName()));
return (new TransformResponse(new SystemClock(), $this->version()))($this->result(), $outcome->status());
```

| Answer | `data` |
|--------|--------|
| success | `@type` (process name, first letter lower case) plus the public fields of the response |
| 400 | `{@type: 'validation', fields}` |
| 404 | `{@type: 'notFound', context}` |
| 409 | `{@type: 'concurrencyConflict', reason, aggregate, context, violated}` |
| 422 | `{@type: 'ruleViolation', rule, messageKey, context}` |
| other 4xx | the fields of the response as given (a node reject: its context as flat data, the declared key last, e.g. `{"messageKey": "access.invalidCredentials"}` for a 401 without payload), no `@type`; the 401 of the missing actor answers no data |
| 5xx | empty |

An empty `context` encodes as `{}`. The context keys the 400 form does not know are not part of its answer.

**Dates on the wire.** A `DateTimeInterface` anywhere in `data` (a response field, nested arrays and plain objects, a reject context) goes out as an RFC 3339 string (`DateTimeInterface::ATOM`, e.g. `2026-10-17T12:30:45+02:00`, keeping the value's own offset), never as json_encode's `{date, timezone_type, timezone}`; `null` stays `null`, `JsonSerializable` values and enums keep their own form.

**Message key in `data`.** The key a node declares for a 4xx status with `rejects(status, key)` wins; without a declaration it is the key the node names with its `reject`. That *declared* key (`ProcessOutcome::declaredMessageKey()`) joins the `data` of every 4xx as the last member (the 422 form carries it at its own place and the declared key replaces it there), an empty key names none. A reject passed through a call node (`MapResponse`) therefore answers the declared key of its node, and for 400, 404 and 409 only that one. The keys of the package's own layers (`process.inputInvalid`, `process.actorMissing`, `process.transientConflict`) are never declared and never reach `data`.

**Field paths.** The input check answers its paths below the input root (`customer`, `items[1].qty`). A node fed by one input property declares it with `input('customer')`; a 400 reject of that node answers its findings as `customer.<field>`.

**Other answer values** (`src/Data/`): `ReadResult` is the one form for a list and a single read, `Reference` is the write-side answer value (root identifier plus child identifiers, never objects).

```php
$page = ReadResult::ofList([new Reserved('A-1', 2), new Reserved('B-2', 1)], 17);
$page->total();   // 17
$page->first();   // Reserved {sku: 'A-1'}
ReadResult::ofOne(null)->isEmpty(); // true

$reference = new Reference('O-A-1', ['lines' => ['L-1', 'L-2']]);
$reference->root();     // 'O-A-1'
$reference->children(); // ['lines' => ['L-1', 'L-2']]
```

---

## Transactions

`Bracket` wraps the run:

| Situation | Behaviour |
|-----------|-----------|
| `transactional(false)` | no bracket, no connection needed |
| `transactional` (default) and no connection | `ConfigurationException` (500, message key `process.connectionMissing`) |
| top-level run, no transaction open | opens and ends its own transaction |
| a transaction is open (the surrounding run's, or somebody else's) | works in a savepoint `sp_{depth}`; never starts a transaction |
| sub-process of a run without a transaction | no bracket |

A success commits (releases the savepoint). An answer of 400 and above, or an exception, rolls back (to the savepoint); an exception is thrown on to `MapThrowable` and stays the cause: a rollback is only issued while a transaction is open (a node may have ended it, or the database an implicit commit), and a failing rollback does not replace the original error. A savepoint needs a connection that implements `SavepointInterface`, otherwise the run ends with 500 (`ConfigurationException`).

**Sub-process.** A node runs an inner process with the surrounding context as parent. The inner run works in `sp_1`; if it rejects, only its writes are rolled back and the outer node decides — reject again, or route on:

```php
final class ReleaseBatch
{
    public function __construct(
        private ProcessRunner $runner,
        private ProcessDefinition $inner,
        private \Closure $innerNodes,
    ) {
    }

    public function __invoke(ProcessContext $context): NodeResult
    {
        $outcome = $this->runner->run($this->inner, $context->input(), $context->actor(), $this->innerNodes, $context);

        return (new MapOutcome())($outcome, Route::OnFalse, $this->inner->getName());
    }
}

$pdo->exec('CREATE TABLE ledger (note TEXT)');
$write = static fn(string $note): \Closure => static function (ProcessContext $context) use ($pdo, $note): NodeResult {
    $pdo->prepare('INSERT INTO ledger (note) VALUES (?)')->execute([$note]);

    return NodeResult::ok(['note' => $note]);
};

$inner = ProcessDefinition::for(stdClass::class, 'InnerRelease')->node('Inner\Release')->terminal()->build();
$innerNodes = static fn(string $class): object => static function (ProcessContext $context) use ($write): NodeResult {
    $write('inner')($context);

    return NodeResult::reject(ResponseStatus::RuleViolation, 'batch.rule');
};

$batch = ProcessDefinition::for(stdClass::class, 'Batch')
    ->node('Batch\Prepare')->onTrue('Batch\Release')
    ->node('Batch\Release')->onTrue('Batch\Finish')->onFalse('Batch\Finish')
    ->node('Batch\Finish')->terminal()
    ->build();

$outcome = $runner->run($batch, new stdClass(), null, static fn(string $class): object => match ($class) {
    'Batch\Prepare' => $write('prepared'),
    'Batch\Release' => new ReleaseBatch($runner, $inner, $innerNodes),
    'Batch\Finish' => $write('finished'),
});
// ResponseStatus::Success; ledger holds 'prepared' and 'finished', the 'inner' row is gone
```

A process of another bounded context, called inside an open transaction, is a run of its own without parent and works in `sp_0`. A process opts out with `transactional(false)`; it then runs without bracket and carries its own consistency.

## Events

Events travel with the node result (`->withEvents(...)`); the package never sends them anywhere.

- A successful run reports the events of all steps in execution order (`$outcome->events()`), including those a sub-process node passes up (`MapOutcome` carries them).
- A rejected result carries no events; a run that ends in a reject or a failure reports none.
- **The caller publishes after the commit.** The runner has already committed when `run()` returns:

```php
$outcome = $runner->run($definition, new PlaceOrder('ACME', 2), new Buyer('b-1'), $nodeFactory);

$published = [];
foreach ($outcome->events() as $event) {
    $published[] = $event; // with a PSR-14 dispatcher: $dispatcher->dispatch($event);
}
```

## Loops

A route may point back to an earlier node. `iteration($node)` counts the passes; the way out is an explicit route (usually `onExit`). `loopLimit()` caps the node steps of one run (default 100); the step that would exceed the limit never executes, the run ends with 500 and `LoopLimitExceededException` as `cause()`. A sub-process counts its steps on its own.

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

$outcome = $runner->run($poll(2), new stdClass(), null, $pollFactory);
// ResponseStatus::InternalError, $outcome->cause() instanceof LoopLimitExceededException
```

## Response infrastructure and clock

`ContextResponse` collects data, events and errors of a context tree; `TransformResponse` turns it into an immutable `DomainResponse` (`getStatus()`, `getData()`, `getEvents()`, `getErrors()`, `getMetadata()`). Time comes from an injected PSR-20 clock, never from the wall clock: `new TransformResponse(ClockInterface $clock, string $version = '')`. `SystemClock` and `FixedClock` (deterministic tests) come with `jardissupport/scheduling` ^1.2.

```php
$transform = new TransformResponse(new SystemClock(), 'v1');
$response = $transform((new ContextResponse('order'))->addData('orderNo', 'O-1'));
$response->getStatus();   // 200
$response->getMetadata(); // duration, contexts, timestamp, version
```

---

## Layer rules

- The application layer builds the definition and starts the runner; the domain core never imports workflow classes.
- Nodes stay thin and delegate to domain services. A node does not publish events and does not dispatch.
- The package publishes nothing and dispatches nothing; the caller owns the events of the outcome.

## Testing against generated code

The generated process classes of the builder run as PHP against the working tree of this package in `tests/Smoke`:
copies of the builder goldens (`tests/Smoke/Fixture`, builder commit named in each file header) plus stubs of the
generat's foreign classes (`tests/Smoke/Support`). `tests/bootstrap.php` maps the generat namespaces to those
directories, so no `composer.json` of a consumer and no path repository is involved - packages come from Packagist only.
`make phpunit` runs the suite, `make phpstan-smoke` analyses it; a finding that sits in the generat is listed in
`phpstan-smoke.neon` for the renderer, never worked around in the smoke code.

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
