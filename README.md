# Jardis Workflow

![Build Status](https://github.com/jardisSupport/workflow/actions/workflows/ci.yml/badge.svg)
[![License: MIT](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE.md)
[![PHP Version](https://img.shields.io/badge/PHP-%3E%3D8.2-777BB4.svg)](https://www.php.net/)
[![PHPStan Level](https://img.shields.io/badge/PHPStan-Level%208-brightgreen.svg)](phpstan.neon)
[![PSR-12](https://img.shields.io/badge/Code%20Style-PSR--12-blue.svg)](phpcs.xml)
[![Coverage](https://img.shields.io/badge/Coverage-99.22%25-brightgreen.svg)](https://github.com/jardisSupport/workflow)

> Part of the **[Jardis Business Platform](https://jardis.io)** — Enterprise-grade PHP components for Domain-Driven Design

Directed workflow engine for multi-step process orchestration. Define handler graphs with status-based transitions, propagate every step's result through a typed context, and wire it all up with a fluent builder API. Each handler returns a `WorkflowResult` that determines the next step.

---

## Features

- **Directed Handler Graph** — connect handlers as nodes with explicit per-status transitions
- **Status-Based Transitions** — `onSuccess`, `onFail`, `onRetry`, `onSkip`, `onCancel`, `onTimeout`, `onError`, `onPending`
- **Typed Execution Context** — `WorkflowContext` carries every handler invocation as an entry in an ordered execution log; `getPrevious()` exposes the immediate predecessor's result without the handler needing to know who that was
- **Lossless History** — re-invocations of the same handler (retry loops, cross-branch revisits) append a new entry instead of overwriting; `getAll(Foo::class)` returns every invocation, `getLatest(Foo::class)` the most recent
- **Fluent Builder API** — `WorkflowBuilder` + `WorkflowNodeBuilder` wire the graph without configuration arrays
- **Handler Factory** — inject a closure to resolve handlers from a DI container
- **WorkflowResult** — typed value object with status constants; no ambiguous truthy/falsy returns

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

$workflow = new Workflow();
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

// Handler using named transitions (retry loop)
class ChargePaymentHandler
{
    public function __invoke(Order $order, WorkflowContextInterface $context): WorkflowResult
    {
        // Count prior invocations from the chain — every retry has a fresh entry
        $attempt = count($context->getAll(self::class)) + 1;

        $result = $this->gateway->charge($order->total);

        if ($result->isTemporaryFailure()) {
            return new WorkflowResult(WorkflowResult::ON_RETRY, ['attempt' => $attempt]);
        }

        if (!$result->isSuccess()) {
            return new WorkflowResult(WorkflowResult::STATUS_FAIL, ['error' => $result->message]);
        }

        return new WorkflowResult(WorkflowResult::STATUS_SUCCESS, ['chargeId' => $result->id]);
    }
}

// Wire retry back to the same handler
$config = (new WorkflowBuilder())
    ->node(ChargePaymentHandler::class)
        ->onSuccess(FulfillOrderHandler::class)
        ->onFail(NotifyFailureHandler::class)
        ->onRetry(ChargePaymentHandler::class)   // loop back
    ->build();

// Inject handlers from a DI container
$workflow = new Workflow(fn(string $class) => $container->get($class));

$context = $workflow($config, $order);

// Inspect the chain — ordered execution log, same handler may appear multiple times
foreach ($context->getChain() as $entry) {
    echo "{$entry['handler']}: {$entry['result']->getStatus()}\n";
}
```

## Documentation

Full documentation, guides, and API reference:

**[docs.jardis.io/en/support/workflow](https://docs.jardis.io/en/support/workflow)**

## License

This package is licensed under the [MIT License](LICENSE.md).

---

**[Jardis](https://jardis.io)** · [Documentation](https://docs.jardis.io) · [Headgent](https://headgent.com)

<!-- BEGIN jardis/dev-skills README block — do not edit by hand -->
## KI-gestützte Entwicklung

Dieses Package liefert einen Skill für Claude Code, Cursor, Continue und Aider mit. Installation im Konsumentenprojekt:

```bash
composer require --dev jardis/dev-skills
```

Mehr Details: <https://docs.jardis.io/skills>
<!-- END jardis/dev-skills README block -->
