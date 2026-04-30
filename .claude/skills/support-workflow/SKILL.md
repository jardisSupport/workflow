---
name: support-workflow
description: jardissupport/workflow - Multi-step process orchestration. Use when working with Workflow, step execution, or jardissupport/workflow.
user-invocable: false
---

# WORKFLOW_COMPONENT_SKILL
> `jardissupport/workflow` | NS: `JardisSupport\Workflow` | PHP 8.2+

## ARCHITECTURE
```
Workflow(__invoke($config, ...$params))
  → push WorkflowContext as last param
  → nodes[0] handler → WorkflowResult
  → context->append($fqcn, $result)   // pushes new entry; never overwrites
  → determineNextHandler(result, config):
      hasExplicitTransition? → transitions[$transition]
      isSuccess?             → transitions[onSuccess]
      else                   → transitions[onFail]
  → loop until no next node
  → return WorkflowContextInterface
```

Contracts: `jardissupport/contract` (`WorkflowInterface`, `WorkflowConfigInterface`, `WorkflowContextInterface`, `WorkflowResultInterface`, `WorkflowBuilderInterface`, `WorkflowNodeBuilderInterface`)

## CLASSES
| Class | Role |
|-------|------|
| `Workflow` | Engine: pushes WorkflowContext into params, executes nodes, returns context |
| `WorkflowConfig` | Node registry + transition maps |
| `WorkflowContext` | Mutable ordered execution log; FQCN-keyed lookups via `getLatest`/`getAll` (implements `WorkflowContextInterface`) |
| `WorkflowResult` | VO: status + data + transition (implements `WorkflowResultInterface`) |
| `WorkflowBuilder` | Fluent config builder (implements `WorkflowBuilderInterface`) |
| `WorkflowNodeBuilder` | Transition config per node (implements `WorkflowNodeBuilderInterface`) |

## API

### WorkflowResult
```php
new WorkflowResult(WorkflowResult::STATUS_SUCCESS, $data);  // → onSuccess routing
new WorkflowResult(WorkflowResult::STATUS_FAIL, $errors);   // → onFail routing
new WorkflowResult(WorkflowResult::ON_RETRY, $data);        // explicit → onRetry (bypasses status routing)
new WorkflowResult(WorkflowResult::ON_PENDING, $data);      // explicit → onPending

$result->getStatus();              // string
$result->getData();                // mixed
$result->getTransition();          // ?string
$result->hasExplicitTransition();  // bool — true when ON_* used as status
```

**Status constants:** `STATUS_SUCCESS='success'`, `STATUS_FAIL='fail'`
**Transition constants:** `ON_SUCCESS`, `ON_FAIL`, `ON_ERROR`, `ON_TIMEOUT`, `ON_RETRY`, `ON_SKIP`, `ON_PENDING`, `ON_CANCEL`

### WorkflowContext
Ordered execution log — every handler invocation appends a new entry. Re-invocations of the same handler (retry loops, cross-branch revisits) **never overwrite earlier entries**, so history is lossless.

```php
$context->append($fqcn, $result);   // void — pushes; called by engine, not by handlers
$context->getPrevious();            // ?WorkflowResultInterface — last appended (immediate predecessor)
$context->getLatest($fqcn);         // ?WorkflowResultInterface — most recent invocation of that handler
$context->getAll($fqcn);            // list<WorkflowResultInterface> — every invocation, in execution order
$context->getChain();               // list<array{handler: class-string, result: WorkflowResultInterface}>
```

### Builder
```php
$config = (new WorkflowBuilder())
    ->node(ValidateHandler::class)
        ->onSuccess(PaymentHandler::class)
        ->onFail(RejectHandler::class)
    ->node(PaymentHandler::class)
        ->onSuccess(ShippingHandler::class)
        ->onFail(NotifyHandler::class)
        ->onRetry(PaymentHandler::class)
    ->node(ShippingHandler::class)
    ->node(RejectHandler::class)
    ->node(NotifyHandler::class)
    ->build();  // WorkflowConfigInterface
```
`WorkflowNodeBuilder` methods: `onSuccess()`, `onFail()`, `onError()`, `onTimeout()`, `onRetry()`, `onSkip()`, `onPending()`, `onCancel()`, `node()`, `build()`

### Handler contract
```php
use JardisSupport\Contract\Workflow\WorkflowContextInterface;

class MyHandler {
    public function __invoke(Order $order, WorkflowContextInterface $context): WorkflowResult {
        // context is ALWAYS the last parameter
        $previous = $context->getPrevious();             // result of the handler that routed here
        $myHistory = $context->getAll(self::class);      // prior invocations of this handler (for retry counting)
        return new WorkflowResult(WorkflowResult::STATUS_SUCCESS, ['key' => 'value']);
    }
}
```
- Must be callable (`__invoke`) and return `WorkflowResultInterface` (canonical: `WorkflowResult`)
- Engine appends `WorkflowContext` as the **last** parameter to every invocation
- Idiomatic retry counter: `$attempt = count($context->getAll(self::class)) + 1;`
- `InvalidArgumentException` thrown if handler does not return `WorkflowResultInterface`

### Workflow execution
```php
$workflow = new Workflow();                                            // direct
$workflow = new Workflow(fn(string $class) => $container->get($class)); // with factory

$context = $workflow($config, $request, $user);
// $context->getPrevious()              → WorkflowResult of last executed handler
// $context->getLatest(Foo::class)      → most recent invocation of Foo (or null)
// $context->getAll(Foo::class)         → every invocation of Foo (list)
// $context->getChain()                 → full ordered execution log
```

## LAYER RULES
- Application: build `WorkflowConfig`, execute `Workflow`
- Domain: NEVER imports Workflow classes
- Handlers: thin orchestration — delegate to domain services
- `WorkflowResult`: return type contract, not a domain concept
- `WorkflowContext`: passed by reference (mutable); handlers READ it, the engine WRITES it
