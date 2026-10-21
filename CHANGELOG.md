# Changelog

All notable changes to `jardissupport/workflow` are documented in this file.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
versioning follows [SemVer](https://semver.org/). Releases before 2.2.0 are listed in the git history and the GitHub releases.

## [4.1.0] - Unreleased

### Added

- `ProcessOutcome::withResponse(object $response): self`: a copy with another answer, every other field (status, errors, events, message key, context) as it was; the outcome stays immutable. For whoever reshapes the typed answer of a run (the wire projection of a door) without rebuilding the outcome by hand.
- `ProcessOutcome` implements `JardisSupport\Contract\Kernel\AnswerableOutcomeInterface` (extends `OutcomeInterface`; needs a contracts release that ships it) and gains `isFailed(): bool` (status 400 and above). No existing method changes. The answer closures of `jardissupport/response` read the outcome through the interface.
- `ProcessDefinition::answerResponse(string $responseClass, string ...$fields)` / `getAnswerResponseClass()` / `getAnswerResponse()`: the typed answer of the whole process. With `Answer::Merged` the united data of all steps (later wins) is projected onto the declared fields of the class (none named = all constructor fields; fields not declared fall away; a required field without a value or an unknown class throws `ConstructionException`), whatever kind and data the last node has. Declared without `Answer::Merged`, `build()` throws a `ConstructionException`. Without a process answer class `Merged` stays byte-identical to 4.0.
- `Handler\Context\ConstructResponse` (shared construction of a typed response object from fields, used by `HydrateResponse` and `BuildOutcome`; messages of the node case unchanged). 27 classes.

### Changed

- `HydrateResponse` does not apply the `response()` class of a node that ends the run (taken route = terminal) when the process declares an answer class: the process answer is not the class of its last node. Nodes that do not end the run keep their class; processes without a process answer class are unchanged.

## [4.0.0] - Unreleased

### BREAKING

- The package is the engine and nothing else (26 classes). The door layers leave `workflow`: input validation, actor check, failure forms, envelope data (`BuildResponseData`), `ReadResult`, `Reference`, `ConfigurationException`, `TransformResponse` and the response objects move to `jardissupport/response`; the transaction bracket and the connection resolution move to `jardissupport/repository`. `CheckRequires` is removed without a successor class.
- `ProcessRunner::forKernel()` is removed, `ProcessRunner` has no constructor any more (`new ProcessRunner()`).
- `ProcessRunner::run(…, ?ProcessContext $parent = null)` becomes `run(…, int $depth = 0)`: the depth of the calling run plus one for a sub-process.
- Failure forms: the engine builds none and answers no 5xx. `ProcessOutcome` loses `cause()` and `declaredMessageKey()`, gains `messageKey()` and `context()`; a reject carries only status, message key, context and errors. Throwables (`ConstructionException`, `UnroutedStatusException`, `LoopLimitExceededException`, a node's own) leave `run()` unchanged.
- `MapOutcome` is removed: a sub-process node maps the answer of the inner door with `MapResponse` (`__invoke(DomainResponseInterface, ?Route $onReject = null)`), which now also carries the Domain events of the callee (Internal events stay behind: they are the write gesture of the callee's aggregate).
- Paths: `Handler/{Failure,Response,Start,Transaction}/*` and `Data/{Failure,Response}/*` are gone; `tests/Smoke` and the wire contract leave the package.
- `require` shrinks to `php` and `jardissupport/contracts` ^2.13 (`repository`, `scheduling`, `psr/log` are no longer required).

### Added

- `Exception\CalleeFailureException` (`final`, `extends \RuntimeException implements ReportedFailureExceptionInterface`): `MapResponse` throws it on a 5xx of a called operation (500-599, message unchanged, also with `$onReject`). The inner door has already reported the cause, so the outer door keeps the 500 and does not log it a second time. Any other status without a node meaning (302, 418) still throws a plain `\RuntimeException`. `require` raises `jardissupport/contracts` to ^2.13 (the marker `Kernel\ReportedFailureExceptionInterface`).
- `Data\Answer` (`LastNode`, `Merged`) and `ProcessDefinition::answer(Answer)` / `getAnswer()`: `LastNode` (default) answers the data of the last step, `Merged` the data of all executed steps in run order (later wins, objects through `get_object_vars`, a non-empty list throws `ConstructionException`; `null` and `[]` add no field).

## [3.0.1]

### Added

- 200-empty case in the wire contract (`tests/Smoke/Contract/wire-contract.json`): a process whose last node answers
  no data of its own (rule node, `VoucherRedeemCheck`) answers 200 with the envelope `data` `{"@type": "voucherRedeemCheck"}`.
  `WireContractTest` now checks the case keys and the status of each of the eight cases.

### Fixed

- Documentation only: a last node without data does not answer `data: {}` but `data` with the `@type` only (README, skill); the behaviour is unchanged.

## [3.0.0]

### BREAKING

- **No more handing on between nodes.** The node decorator `Handler/Outcome/PassThrough` is removed (the class is
  gone). Before, a routed `NodeResult` without data (`NodeResult::route(Route::OnTrue)`, an event node, a rule
  node) took over the data of the node before it. Now the answer of a successful run is the data of the last
  node only: a last node without data answers an empty response (`response()` is an empty object, the envelope
  `data` is `{}`), and a node that follows a node without data sees `null` from `previous()?->data()`.
- **Migration:** a node that read the answer of its predecessor through `previous()` reads it explicitly with
  `latest('<node>')` / `all('<node>')` (typed getters of a generated context already do); a process whose last
  node is an event or rule node without data and which should answer data lets that node return the data itself
  (`NodeResult::ok($data)`) or reads it with `latest()`.

### Added

- **Wire contract** `tests/Smoke/Contract/wire-contract.json`: the envelope bytes per status (200, 400, 401, 404,
  409, 422, 500) that generated process handlers answer, compared byte for byte by `WireContractTest`, rewritten
  only with `UPDATE_WIRE_CONTRACT=1`. Shipped in the Composer archive (`.gitattributes`: only this path of `tests/`
  is not `export-ignore`).

### Changed

- The smoke fixtures are copies of the current generat (`BuildResponseData`, `transformer()`, actor channel, nodes
  that extend the domain context, `input()` declarations); new fixture `VoucherRedeemCheck` (actor, rule node).

## [2.3.0]

### Added

- **`Handler/Node/MapOutcome`** - the sub-process node in one call. Maps the `ProcessOutcome` of an
  inner run to the `NodeResult` of the calling node: success `ok((array) response)` with all events
  of the inner run; 4xx `route($onReject)` when given, otherwise a reject with the same status and the
  inner failure form unpacked (400 `fields`, 409 `reason`/`aggregate`/`violated`, 422 `rule`, plain
  context), so the outer answer has the same wire form; 5xx throws `cause()` (without one a
  `RuntimeException` naming the process and the status). Message keys as in `MapResponse`: a declared
  key travels, the keys of the package's own layers never do. Before, every sub-process node
  hand-wrote this mapping.

## [2.2.0]

### Added

- **`messageKey` per field of the 400.** Every finding of the start validation now carries
  `messageKey` = `validation.missing` | `validation.invalid`, derived from its `reason`
  (`Handler/Failure/DescribeFieldFinding`). A finding whose validator text is empty gets the
  standard text (`Field is required.` / `Field is invalid.`). Validator texts stay untouched;
  the `errors()` list is unchanged.
- **The cause of a 5xx is logged.** New outermost run layer `Handler/Failure/LogCause` writes
  `error(<message>, ['exception' => <throwable>, 'process' => <name>])` once per cause, in the
  owning run only (a nested run does not log what its owner logs). 4xx answers (including the 409
  stale write) log nothing; without a logger nothing happens; a failing logger never replaces the answer.
- `ProcessRunner::__construct(..., ?LoggerInterface $logger = null)` (additive); `forKernel()` passes
  `$kernel->logger()`.
- `psr/log` ^3.0 is now a direct requirement.
