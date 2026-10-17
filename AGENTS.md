# jardissupport/workflow

Process engine for one request-response use case: a declared node graph (`ProcessDefinition`), run by `ProcessRunner`, answered as a typed `ProcessOutcome` (status, response, errors, events). Details: `README.md`, skill `support-workflow`, https://docs.jardis.io/en/support/workflow

## Layout (`src/`)

- `ProcessRunner.php` — composition only: run layers (`MapThrowable`, `ValidateInput`, `CheckActor`, `Bracket`) around the core; node decorators (`GuardLoop`, `CollectEvents`, `HydrateResponse`, `PassThrough`, `ApplyRuleMode`)
- `Data/` — `ProcessDefinition`, `ProcessContext`, `NodeResult`, `Route`, `RuleMode`, `ProcessOutcome`, `ReadResult`, `Reference`, `Failure/*` (four failure forms), `Response/*` (`ContextResponse`, `DomainResponse`)
- `Handler/` — one closure class per concern with exactly one public entry point (`__invoke`): `Start/`, `Transaction/`, `Failure/`, `Events/`, `Loop/`, `Node/`, `Outcome/`, `Routing/`, `Context/`, `Response/`
- `Exception/` — `WorkflowException` base, `ConstructionException`, `ConfigurationException`, `LoopLimitExceededException`, `UnroutedStatusException`

## Commands (always `make`)

`make phpunit` · `make phpunit-coverage` · `make phpstan` · `make phpcs` · `make install` · `make shell`

## Rules

- Closure-Orchestrator: atomic closure classes (one public `__invoke`), the runner only composes them, no logic of its own
- Everything is `final`; the one exception is `ProcessContext` (generated processes extend it with typed getters, the chain stays immutable)
- Constructor injection, no static state; the clock is an injected PSR-20 `ClockInterface`
- The package publishes and dispatches nothing: events travel on the `NodeResult`, the caller publishes after the commit
- A reject is not a route; routing is strict (an undeclared route throws `UnroutedStatusException`)
- Tests are integration tests (SQLite, real validator); mock ports only, no assertion weakening
- The domain core never imports workflow classes
