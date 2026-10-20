# jardissupport/workflow

Process engine for one request-response use case: a declared node graph (`ProcessDefinition`), run by `ProcessRunner`, answered as a typed `ProcessOutcome` (status, response, errors, events). The engine and nothing around it: input check, actor check, transaction, failure forms, envelope data and logging belong to the door in front of the runner (`jardissupport/response`). Details: `README.md`, skill `support-workflow`, https://docs.jardis.io/en/support/workflow

## Layout (`src/`)

- `ProcessRunner.php` — composition only, no constructor (`new ProcessRunner()`), `run(…, int $depth = 0)`: the core (`CreateContext`, `Workflow` engine, `BuildOutcome`) with the node decorators (`GuardLoop`, `CollectEvents`, `HydrateResponse`, `ApplyRuleMode`); what a node throws leaves `run()` unchanged
- `Data/` — `Answer` (`LastNode`, `Merged`), `ProcessDefinition`, `ProcessContext`, `NodeResult`, `Route`, `RuleMode`, `ProcessOutcome` (status, response, errors, events, message key and context of a node reject), `RunRequest`, `WorkflowConfig`, `Builder/WorkflowConfigBuilder`
- `Handler/` — one closure class per concern with exactly one public entry point (`__invoke`): `Events/`, `Loop/`, `Node/` (`MapResponse`, `ResolveNode`, `ApplyRuleMode`), `Outcome/`, `Routing/`, `Context/` (`CreateContext`, `HydrateResponse`, `ConstructResponse`)
- `Exception/` — `WorkflowException` base, `CalleeFailureException` (final, `RuntimeException` with the marker `ReportedFailureExceptionInterface`: `MapResponse` throws it on a 5xx), `ConstructionException`, `LoopLimitExceededException`, `UnroutedStatusException`

## Commands (always `make`)

`make phpunit` · `make phpunit-coverage` · `make phpstan` · `make phpcs` · `make install` · `make shell`

## Rules

- Closure-Orchestrator: atomic closure classes (one public `__invoke`), the runner only composes them, no logic of its own
- Everything is `final`; the one exception is `ProcessContext` (generated processes extend it with typed getters, the chain stays immutable)
- Constructor injection, no static state
- The package publishes and dispatches nothing and opens no transaction: events travel on the `NodeResult`, the door commits and the caller publishes after the commit
- A reject is not a route; routing is strict (an undeclared route throws `UnroutedStatusException`)
- Tests are integration tests with the real runner (`new ProcessRunner()`); mock ports only, no assertion weakening
- Validation, actor check, transaction, failure forms, envelope data and logging are not part of this package; they live in the door (`jardissupport/response`, `jardissupport/repository`)
- The domain core never imports workflow classes
