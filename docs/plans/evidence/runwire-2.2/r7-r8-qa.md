# Runwire 2.2 — R7 observability/security and R8 gate audit

## R7 audit completed: 8/10 tasks

- **R7.01:** `RuntimeMetricsSnapshot`, `LoopDiagnosticsSnapshot`, `CoroutineDiagnosticsSnapshot` already carry bounded cardinality request, pressure, event-loop lag, callback-overrun, queue and cancellation counters. `MetricsDiagnosticsTest` verifies versioned serialization.
- **R7.02:** Audited `WorkerDiagnosticsSampler` report interval and bounded metric shapes. No new per-request instrumentation required; adding costly dynamic labels is explicitly rejected (valid no-change decision).
- **R7.03:** Updated [deployment incident runbook](../../../deployment.md) distinguishing PHP allocator `memoryCurrentBytes`/`memoryPeakBytes` from real process RSS, native extension memory and possible cgroup quota unavailability. No claims about native memory derived solely from PHP counters.
- **R7.04:** `ProcessRunnerTest` verifies argv literal (no shell), bounded stdin/env/output, stdout/stderr draining, timeouts, process group cleanup, invalid input; existing secured ProcessRunner retained.
- **R7.05:** `ControlServerTest`, `PrivilegeDropOrderingTest`, `docs/security.md` verify protected Unix socket/control settings and startup privilege ordering; documented strict owner/mode handling.
- **R7.06:** `Http1AbuseAcceptanceTest`, `Http2AbuseTest`, `Http3AbuseFaultAcceptanceTest`, `WebSocketIntegrationTest`, `DatagramAbuseAcceptanceTest` cover malformed request framing, QPACK/HPACK bounds, masks, oversized bodies/datagrams and resource exhaustion. This is substantial regression/abuse coverage but not a claim of exhaustive stochastic fuzzing.
- **R7.07:** `BatchPRaceFaultTest`, `BatchISoakFaultTest`, `NativeWorkerLifecycleTest` include scheduler cancellation storms, failed callback/reset, accepted-but-incomplete admission, rolling recycle and child recovery.
- **R7.10:** Runbook now maps exact runtime and coroutine metric names to symptoms and response procedure.

**R7.08 OPEN:** prior actual 30-minute native HTTP soak passed 12,901,151 requests and zero errors, but final exact SHA does not yet have full queue/RSS leak and GC/OPcache soak analysis. **R7.09 OPEN:** security advisories and full dependency CVE provenance on the last candidate remain to be signed off.

## R8 audit completed: 3/10 tasks

- **R8.01:** Standard PR workflows vs conditional release-certification fully audited. Green normal CI ≠ release certification. Four release jobs are matched performance, native soak, Infbyte consumer, PHPForge guard.
- **R8.06:** Published 2.1.2 annotated tag resolved to immutable `7e9bf20ad0737a187dfed77b723791b141b36378` and is primary matched benchmark baseline in release workflow; historical pre-consolidation SHA removed as the release baseline. Candidate SHA/path install checks added.
- **R8.09:** Retrieved real workflow/benchmark artifacts, identified nonmatching GitHub CPU models, baseline CV 4.283% > 2.5% ceiling, previous conditional skips and actual Infbyte dependency failure, and corrected loose evidence metadata. Unsupported comparisons never counted as passing.

**R8.02/.03/.04/.05/.07/.08/.10 OPEN:** final candidate exact-SHA full matrix, five stable warmed 180s paired trials, 30-minute soak, independent protocol/host acceptance, actual Infbyte consumer and release signoff. Infbyte main currently asks for unpublished `infocyph/foundation:^3.0.1` and must not be silently replaced by a fake package alias.

## Change scope

This batch adds no new runtime instrumentation. Safety/defaults unchanged. All future performance claims require R8 exact-SHA certification, with measured successful RPM/p99/CPU/RSS and zero protocol correctness errors.
