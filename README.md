# Jardis Workflow

![Build Status](https://github.com/jardisSupport/workflow/actions/workflows/ci.yml/badge.svg)
[![License: MIT](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE.md)
[![PHP Version](https://img.shields.io/badge/PHP-%3E%3D8.2-777BB4.svg)](https://www.php.net/)
[![PHPStan Level](https://img.shields.io/badge/PHPStan-Level%208-brightgreen.svg)](phpstan.neon)
[![PSR-12](https://img.shields.io/badge/Code%20Style-PSR--12-blue.svg)](phpcs.xml)
[![Coverage](https://img.shields.io/badge/Coverage-99.22%25-brightgreen.svg)](https://github.com/jardisSupport/workflow)

> Part of **[Jardis](https://jardis.io)** — the Domain-Driven Design platform for PHP. You model your domain; Jardis generates the production-ready hexagonal code (DTOs, Command/Query handlers, repositories, persistence). This package is part of the open-source foundation that generated code runs on.

Directed workflow engine for multi-step process orchestration in PHP. Define handler graphs with named transitions, propagate every step's result through a typed context, and wire it all up with a fluent API. Each handler returns a `WorkflowResult` whose status — one of seven `ON_*` constants — picks the next step.

---

## Features

- **Directed Handler Graph** — connect handlers as nodes with explicit per-status transitions
- **Seven Named Transitions** — `onSuccess`, `onFail`, `onTimeout`, `onSkip`, `onCancel`, `onEvent`, `onExit` (loop/block termination)
- **R5 Routing-Safety** — when a transition target is not a registered node, the engine returns control to the caller (no dispatch, no exception)
- **Opt-in Strict Routing** — `new WorkflowConfig(strictRouting: true)` requires every status a handler can emit to be a declared transition key; `'STATUS' => null` marks a deliberate terminal end, a missing key raises `UnroutedStatusException`. Default (`false`) preserves the non-strict v1.1.0 behaviour byte-for-byte
- **Typed Execution Context** — `WorkflowContext` carries every handler invocation as an entry in an ordered execution log; `getPrevious()` exposes the immediate predecessor's result without the handler needing to know who that was
- **Lossless History** — re-invocations of the same handler (retry loops, cross-branch revisits) append a new entry instead of overwriting; `getAll(Foo::class)` returns every invocation, `getLatest(Foo::class)` the most recent
- **Fluent Builder API** — `WorkflowBuilder` + `WorkflowNodeBuilder` wire the graph without configuration arrays
- **Handler Factory** — inject a closure to resolve handlers from a DI container
- **WorkflowResult** — typed value object with named status constants; no ambiguous truthy/falsy returns
- **WorkflowState** — recommended typed `WorkflowContextInterface` for process orchestrators: a `payload → original → modified` three-step, passed in as the engine's optional `$context` argument

---

## Installation

```bash
composer require jardissupport/workflow
```

## Quick Start

```php
use JardisSupport\Workflow\Builder\WorkflowBuilder;
use JardisSupport\Workflow\Workflow;
use JardisSupport\Workflow\WorkflowResult;

// Build a two-step graph
$config = (new WorkflowBuilder())
    ->node(ValidateOrderHandler::class)
        ->onSuccess(ChargePaymentHandler::class)
        ->onFail(RejectOrderHandler::class)
    ->node(ChargePaymentHandler::class)
        ->onSuccess(ConfirmOrderHandler::class)
    ->build();

// Workflow is stateless and single-shot. Per-run input is passed as $data and forwarded
// to the handler factory — handlers themselves are invoked with the WorkflowContext only.
$workflow = new Workflow(
    handlerFactory: fn(string $cls, mixed $data): object => new $cls($data),
);
$context  = $workflow($config, $order);

// Inspect the final result and the full chain
$lastResult   = $context->getPrevious();                          // WorkflowResult of last executed handler
$chargeResult = $context->getLatest(ChargePaymentHandler::class); // most recent invocation of that handler
$allCharges   = $context->getAll(ChargePaymentHandler::class);    // every invocation in execution order
$executed     = count($context->getChain());                      // total number of handler invocations
```

## Advanced Usage

```php
use JardisSupport\Contract\Workflow\WorkflowContextInterface;
use JardisSupport\Workflow\Builder\WorkflowBuilder;
use JardisSupport\Workflow\Workflow;
use JardisSupport\Workflow\WorkflowResult;

// Handler using named transitions (retry loop). All handlers share the same signature:
// __invoke(WorkflowContextInterface): WorkflowResultInterface — per-run input is wired in
// by the handler factory (e.g. injected via constructor or set as the BoundedContext payload).
class ChargePaymentHandler
{
    public function __construct(private readonly Order $order) {}

    public function __invoke(WorkflowContextInterface $context): WorkflowResult
    {
        // Count prior invocations from the chain — every retry has a fresh entry
        $attempt = count($context->getAll(self::class)) + 1;

        $gatewayResult = $this->gateway->charge($this->order->total);

        if ($gatewayResult->isTemporaryFailure()) {
            // Service-side timeout translated into a domain transition — loops back via ON_TIMEOUT
            return new WorkflowResult(WorkflowResult::ON_TIMEOUT, ['attempt' => $attempt]);
        }

        if (!$gatewayResult->isSuccess()) {
            return new WorkflowResult(WorkflowResult::ON_FAIL, ['error' => $gatewayResult->message]);
        }

        return new WorkflowResult(WorkflowResult::ON_SUCCESS, ['chargeId' => $gatewayResult->id]);
    }
}

// Wire the timeout retry back to the same handler
$config = (new WorkflowBuilder())
    ->node(ChargePaymentHandler::class)
        ->onSuccess(FulfillOrderHandler::class)
        ->onFail(NotifyFailureHandler::class)
        ->onTimeout(ChargePaymentHandler::class)   // self-loop for retry-like behaviour
    ->build();

// Inject handlers from a DI container; the factory receives both the FQCN and the
// per-run $data passed to $workflow($config, $data).
$workflow = new Workflow(
    fn(string $class, mixed $data): object => $container->get($class)->withOrder($data),
);

$context = $workflow($config, $order);

// Inspect the chain — flat ordered execution log; every entry is a stamped WorkflowResult
foreach ($context->getChain() as $result) {
    echo "{$result->getHandlerFqcn()}: {$result->getStatus()}\n";
}
```

## Strict Routing (Opt-in)

```php
use JardisSupport\Workflow\WorkflowConfig;
use JardisSupport\Workflow\WorkflowResult;

$config = new WorkflowConfig(strictRouting: true);
$config->addNode(ChargePaymentHandler::class, [
    WorkflowResult::ON_SUCCESS => FulfillOrderHandler::class,
    WorkflowResult::ON_FAIL    => null,   // declared terminal — legitimate, silent end
]);
```

With `strictRouting: true`, every status a handler can emit must be a declared transition key
for that node — `'onFail' => null` is a deliberate terminal end. A status with **no key at all**
raises `UnroutedStatusException` (`getNode()` / `getStatus()`). Default is `false`, unchanged
from v1.1.0: an unmapped status silently ends the run, and the R5 hand-off (a mapped target that
is not itself a registered node) stays legal either way.

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
