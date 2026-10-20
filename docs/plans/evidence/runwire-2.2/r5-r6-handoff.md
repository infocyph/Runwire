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

**R6.02, R6.07, R6.08, R6.10 remain OPEN** for full live multi-protocol worker replacement/reload, generation invalidation across all handles, all privilege-transition cases and exact real-host+consumer acceptance. R8 provides final complete real-host/consumer release certification. Do not mark these complete from isolated unit results.

## Batch handoff integrity

These are audit/doc updates only; no runtime code changes after the ByteQueue R3 commit. Full QA of any final source SHA and release promotion evidence remain mandatory. R5 deferred scope is a conscious architecture decision, not an inadvertent incomplete feature.

## R6.08 privilege-transition acceptance — completed

`tests/PrivilegeDropperAcceptanceTest.php` and a PSR-4 compliant `tests/Fixtures/RunwirePrivilegeAuditIdentity.php` use the internal `IdentitySystemInterface` fake rather than mutating the CI runner's real UID/GID. Cases assert supplementary group initialization, GID-before-UID ordering, GID-only operation, non-root rejection, failed group/GID/UID changes, post-transition effective identity verification and no-op policy. Existing `PrivilegeDropOrderingTest` asserts policy applied before worker readiness/bootstrap. [PHPForge QA 39379164190](https://github.com/infocyph/Runwire/actions/runs/39379164190) passed Pest/Pint/analysis on PHP8.4/8.5 stable and lowest dependency lanes. Full root-to-nonroot live host acceptance is a separate R6.10/R8 gate, not inferred from these controlled tests.
