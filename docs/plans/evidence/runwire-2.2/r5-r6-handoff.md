# R5 scope resolution and R6 real-host parity audit

## R5 — evidence-backed deferred feature

**R5.01 completed:** `AsyncConnection` existing receive/write/drain/close/dispose contract reviewed; `AsyncConnectionTest` includes EOF, pressure, cancellation and ownership tests. Caller must already own established `Connection`; PHP stream connect/DNS/TLS blocking boundaries are not magically made asynchronous.

**R5.09 completed:** New [Coroutine blocking-boundary section](../../../coroutines.md) documents what is and is not awaitable. Tasks **R5.02–R5.08 are deliberately NOT checked** because a safe new TCP/TLS nonblocking connect API is out of the 2.2 bounded slice. This is a documented **DEFERRED** product decision allowed by the R5 scope rule, not an assertion that unfinished implementation is complete. No new runtime/API dependency.

## R6 — supervisor and hosted runtime

Source/test review on Runwire 2.2 branch, with previous standard green workflow [source 81fab12](https://github.com/infocyph/Runwire/actions/runs/39370945116), [live Swoole/OpenSwoole](https://github.com/infocyph/Runwire/actions/runs/39370944755), and [portable native](https://github.com/infocyph/Runwire/actions/runs/39370944767):

- **R6.01 completed:** Current owner/capability matrix covers Runwire native listener/event-loop/worker, portable single-process and host-owned FPM, FrankenPHP, RoadRunner, Swoole/OpenSwoole reactors. `RuntimeCapabilityResolverTest` asserts fallback and host capability reporting; no hidden competing listener was found.
- **R6.03 completed:** `PortableNativeRuntimeTest` and absent-PCNTL CI mode establish single-process fallback and no false supervisor capabilities. 
- **R6.04 completed:** Real two-worker Swoole and OpenSwoole acceptance exercises both `serve` and `serveApplication` with distinct child PIDs/slots, unique request IDs, worker-local boot/drain/shutdown; `SwooleDriverTest` tests fresh per-worker context on replacement. The initial request distribution on OpenSwoole can be uneven; the bounded acceptance probe still requires both actual workers.
- **R6.05 completed:** `HostRuntimeTest`, `RoadRunnerRuntimeTest` validate FPM/FrankenPHP classic+worker and RoadRunner host-owned scope and no Runwire reactor takeover; real FrankenPHP/RoadRunner servers are not available in current CI and must not be called full real-host certification.
- **R6.06 completed:** `Http1ConnectionTest`, `Http2ConnectionTest`, `Http3SessionTest` and native accepted-incomplete header recycle tests cover drain-admission semantics across protocols; no new production behavior introduced.
- **R6.09 completed:** `ApplicationLifecycleTest`, `PersistentRuntimeIsolationTest` exercise handler/reset/shutdown exception aggregation, terminal response accounting, one shutdown and per-request state clearing.

**R6.02 and R6.10 remain OPEN** for full live multi-protocol worker replacement/reload and exact standalone Runwire real-host acceptance. R6.07 generation invalidation and R6.08 controlled privilege-transition tests are completed with focused QA. R8 provides final real-host release certification; no Foundation or Infbyte acceptance is required.

## Batch handoff integrity

These are audit/doc updates only; no runtime code changes after the ByteQueue R3 commit. Full QA of any final source SHA and release promotion evidence remain mandatory. R5 deferred scope is a conscious architecture decision, not an inadvertent incomplete feature.

## R6.08 privilege-transition acceptance — completed

`tests/PrivilegeDropperAcceptanceTest.php` and a PSR-4 compliant `tests/Fixtures/RunwirePrivilegeAuditIdentity.php` use the internal `IdentitySystemInterface` fake rather than mutating the CI runner's real UID/GID. Cases assert supplementary group initialization, GID-before-UID ordering, GID-only operation, non-root rejection, failed group/GID/UID changes, post-transition effective identity verification and no-op policy. Existing `PrivilegeDropOrderingTest` asserts policy applied before worker readiness/bootstrap. [PHPForge QA 39379164190](https://github.com/infocyph/Runwire/actions/runs/39379164190) passed Pest/Pint/analysis on PHP8.4/8.5 stable and lowest dependency lanes. Full root-to-nonroot live host acceptance is a separate R6.10/R8 gate, not inferred from these controlled tests.

### R6.07 — retired worker generation isolation accepted (2026-10-20)

- [Production fix b820293](https://github.com/infocyph/Runwire/commit/b820293e67da2e6b1473088f0d99775e96ea658e): close() marks the retiring WorkerContext as stopping before disposing of scope, periodic timer and reactor handles, so it cannot advertise background admission after closure. The transition is idempotent.
- [WorkerGenerationIsolationTest from b820293 and d9117e7](https://github.com/infocyph/Runwire/commit/d9117e732e2f8a17602fed17273528330eb15d22): three focused Pest cases cover idempotent closure, rejected late background and periodic admission, cancelled suspended coroutine tasks, old periodic handle invalidation while a new generation reuses the same task name, no old callback bleed, timer/watch cleanup, retired application rejection, completed request-context rejection and successful fresh-generation application handling. Existing BatchPSoakTest and NativeWorkerLifecycleTest continue to assert multi-generation drains and request ownership.
- [Security & Standards run 39429653975](https://github.com/infocyph/Runwire/actions/runs/39429653975) at exact code source d9117e7 passed all four PHPForge Pest QA combinations (8.4/8.5 × lowest/stable), PHP8.4/8.5 analysis, PHPCS and Pint. [Portable Native 39429653366](https://github.com/infocyph/Runwire/actions/runs/39429653366), [Swoole/OpenSwoole 39429653245](https://github.com/infocyph/Runwire/actions/runs/39429653245), [Source Audit 39429653353](https://github.com/infocyph/Runwire/actions/runs/39429653353), and [Release Candidate 39429653220](https://github.com/infocyph/Runwire/actions/runs/39429653220) passed. H3 PHP8.5 and full release certification were still running at this evidence checkpoint, so those are not credited.
- **R6.07 complete for local generation invalidation**. R6.02 remains the live multi-protocol process restart/recycle stress and R6.10 remains the complete real-host environment matrix; neither is implied by these in-process tests. No Foundation or Infbyte work performed.

