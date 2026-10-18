# jardissupport/workflow

Process engine for one request-response use case: a declared node graph (`ProcessDefinition`), run by `ProcessRunner`, answered as a typed `ProcessOutcome` (status, response, errors, events). Details: `README.md`, skill `support-workflow`, https://docs.jardis.io/en/support/workflow

## Layout (`src/`)

- `ProcessRunner.php` — composition only (`forKernel()` = `ResolveConnection` + constructor; connection nullable): run layers (`MapThrowable`, `ValidateInput`, `CheckActor`, `Bracket`) around the core; node decorators (`GuardLoop`, `CollectEvents`, `HydrateResponse`, `ApplyRuleMode`)
- `Data/` — `ProcessDefinition`, `ProcessContext`, `NodeResult`, `Route`, `RuleMode`, `ProcessOutcome` (incl. the declared message key of a node reject), `ReadResult`, `Reference`, `Failure/*` (four failure forms), `Response/*` (`ContextResponse`, `DomainResponse`)
- `Handler/` — one closure class per concern with exactly one public entry point (`__invoke`): `Start/`, `Transaction/`, `Failure/`, `Events/`, `Loop/`, `Node/`, `Outcome/`, `Routing/`, `Context/`, `Response/` (`TransformResponse`, `BuildResponseData` = the `data` of the envelope)
- `Exception/` — `WorkflowException` base, `ConstructionException`, `ConfigurationException`, `LoopLimitExceededException`, `UnroutedStatusException`

## Commands (always `make`)

`make phpunit` · `make phpunit-coverage` · `make phpstan` · `make phpstan-smoke` · `make phpcs` · `make install` · `make shell`

## Rules

- Closure-Orchestrator: atomic closure classes (one public `__invoke`), the runner only composes them, no logic of its own
- Everything is `final`; the one exception is `ProcessContext` (generated processes extend it with typed getters, the chain stays immutable)
- Constructor injection, no static state; the clock is an injected PSR-20 `ClockInterface`
- The package publishes and dispatches nothing: events travel on the `NodeResult`, the caller publishes after the commit
- A reject is not a route; routing is strict (an undeclared route throws `UnroutedStatusException`)
- Tests are integration tests (SQLite, real validator); mock ports only, no assertion weakening
- `tests/Smoke` runs copies of the builder goldens (generated process classes) against the working tree; stubs live in `tests/Smoke/Support`, never in `src/`
- The domain core never imports workflow classes
