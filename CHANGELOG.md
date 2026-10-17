# Changelog

All notable changes to `jardissupport/workflow` are documented in this file.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
versioning follows [SemVer](https://semver.org/). Releases before 2.2.0 are listed in the git history and the GitHub releases.

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
