# Jardis Workflow

![Build Status](https://github.com/jardisSupport/workflow/actions/workflows/ci.yml/badge.svg)
[![License: PolyForm Noncommercial](https://img.shields.io/badge/License-PolyForm%20Noncommercial-blue.svg)](LICENSE)
[![PHP Version](https://img.shields.io/badge/PHP-%3E%3D8.2-777BB4.svg)](https://www.php.net/)
[![PSR-4](https://img.shields.io/badge/PSR--4-Autoloader-blue.svg)](https://www.php-fig.org/psr/psr-4/)
[![PSR-12](https://img.shields.io/badge/PSR--12-Code%20Style-blue.svg)](https://www.php-fig.org/psr/psr-12/)
[![Coverage](https://img.shields.io/badge/Coverage-94%25-brightgreen.svg)]()

> Part of the **[Jardis Ecosystem](https://jardis.io)** — A modular DDD framework for PHP

Workflow orchestration for PHP — Multi-step process execution with configurable transitions.

---

## Features

- **Fluent Builder API** — Configure workflows with a clean, readable syntax
- **Flexible Transitions** — Success/fail paths plus custom transitions (retry, pending, cancel, etc.)
- **Result Accumulation** — Collect and pass data between workflow steps
- **Call Stack Tracking** — Full visibility into which handlers executed and their results
- **Named Transitions** — Go beyond success/fail with semantic transition names

---

## Installation

```bash
composer require jardissupport/workflow
```

## Quick Start

```php
use JardisSupport\Workflow\Workflow;
use JardisSupport\Workflow\WorkflowConfig;
use JardisSupport\Workflow\WorkflowResult;

// Define workflow configuration
$config = new WorkflowConfig();
$config->addNode(ValidateOrderHandler::class, [
    WorkflowResult::ON_SUCCESS => ProcessPaymentHandler::class,
    WorkflowResult::ON_FAIL => RejectOrderHandler::class,
]);
$config->addNode(ProcessPaymentHandler::class, [
    WorkflowResult::ON_SUCCESS => ShipOrderHandler::class,
    WorkflowResult::ON_RETRY => ProcessPaymentHandler::class,
]);
$config->addNode(ShipOrderHandler::class);
$config->addNode(RejectOrderHandler::class);

// Execute workflow
$workflow = new Workflow();
$result = $workflow($config, $orderId);

// Result contains accumulated data and call stack
$orderData = $result['result'];
$executedHandlers = $result['callStack'];
```

## Fluent Builder

```php
use JardisSupport\Workflow\Builder\WorkflowBuilder;

$config = (new WorkflowBuilder())
    ->node(ValidateOrderHandler::class)
        ->onSuccess(ProcessPaymentHandler::class)
        ->onFail(RejectOrderHandler::class)
    ->node(ProcessPaymentHandler::class)
        ->onSuccess(ShipOrderHandler::class)
        ->onRetry(ProcessPaymentHandler::class)
    ->node(ShipOrderHandler::class)
    ->node(RejectOrderHandler::class)
    ->build();
```

## Handler Implementation

Handlers must be callable (implement `__invoke`) and return a `WorkflowResult`:

```php
use JardisSupport\Workflow\WorkflowResult;

class ProcessPaymentHandler
{
    public function __invoke(mixed ...$params): WorkflowResult
    {
        $orderId = $params[0];
        $accumulated = end($params); // Accumulated results are passed as last parameter

        try {
            $paymentId = $this->processPayment($orderId);
            return new WorkflowResult(
                WorkflowResult::STATUS_SUCCESS,
                ['paymentId' => $paymentId]
            );
        } catch (RetryableException $e) {
            return new WorkflowResult(WorkflowResult::ON_RETRY);
        } catch (Exception $e) {
            return new WorkflowResult(
                WorkflowResult::STATUS_FAIL,
                ['error' => $e->getMessage()]
            );
        }
    }
}
```

## Transition Constants

```php
// Status-based routing
WorkflowResult::STATUS_SUCCESS  // Handler succeeded
WorkflowResult::STATUS_FAIL     // Handler failed

// Named transitions
WorkflowResult::ON_SUCCESS      // Explicit success transition
WorkflowResult::ON_FAIL         // Explicit fail transition
WorkflowResult::ON_ERROR        // Error occurred
WorkflowResult::ON_TIMEOUT      // Operation timed out
WorkflowResult::ON_RETRY        // Should retry
WorkflowResult::ON_SKIP         // Skip to next
WorkflowResult::ON_PENDING      // Waiting for external process
WorkflowResult::ON_CANCEL       // Operation cancelled
```

## Documentation

Full documentation, examples and API reference:

**→ [jardis.io/docs/support/workflow](https://jardis.io/docs/support/workflow)**

## License

This package is licensed under the [PolyForm Noncommercial License 1.0.0](LICENSE).

For commercial use, see [COMMERCIAL.md](COMMERCIAL.md).

---

**[Jardis Ecosystem](https://jardis.io)** by [Headgent Development](https://headgent.com)
