# Runwire 3.0 — PHPForge engineering-principles performance and structural audit

**Review baseline:** branch `plan/runwire-independent-ecosystem-roadmaps`, draft PR #9, baseline source `2da0cd9edda10ddfe0ec5499cc3b45b4e2fad67f`, PHPForge principles `44244c2164bcbd6d995936785ebb56889ad72e46` (2026-10-28).
**Scope:** repository-wide production-source inventory and targeted ownership/hot-path inspection; subsequent proof requires exact-source PHPForge QA and GitHub-worker benchmarks. This is **not** a claim of a completed line-by-line review or production-measured performance improvement.
**Release ledger:** 159/167 QA accepted, 8 OPEN; C4 U15.10 and seven C5 U17 tasks remain OPEN. No task count inflation, merge, tag, external publishing, new repository, or threshold relaxation.

## Engineering decision order

1. Correctness, protocol/security contracts, ownership and deterministic cleanup precede speed.
2. Prefer highest **sustained successful RPM** in a representative PHP 8.4/8.5 deployment, while bounding p99, timeouts, worker resources, queue growth and independent generator saturation.
3. Preserve supported library entry points and meaningful protocol, runtime, security and interoperability boundaries; simplify only redundancy with verified callers and no independently managed lifecycle.
4. Apply PHPForge's *non-blocking* structural triggers as review signals, not automatic quality failures or arbitrary minimum file/class sizes.
5. Use the existing PHPForge analyzers, tests and CI before creating overlapping custom detectors. No suppressions, downgraded gates or reclassified failing runs.

## Repository-wide source inventory

From the GitHub recursive source tree: **437 production PHP files** (~1.79 MiB source), **327 test files** and **93 docs files**. Core directories: `Http/` 148 files; `Network/` 61; `Runtime/` 57; `Supervisor/` 41; `Coroutine/` 39; `Process/` 14; `Metrics/` 12; `WebSocket/` 11; `Loop/` 10; `Exception/` 10; `Protocol/` 7; `Filesystem/` 4; `Executor/` 3; remaining root/other types.

Git tree byte sizes flag **82 files under 600 bytes**, **137 under 1,200 bytes**, and **214 under 2,400 bytes** for *inspection only*. Those numbers are **not** executable-line ratios, trivial-class ratios or proof of needless files. PHPForge's triggers require executable lines and semantic inspection: `small_file_executable_lines_max=40`, `small_file_ratio_review_above=0.35`, `trivial_single_method_class_ratio_review_above=0.20`, `pass_through_class_ratio_review_above=0.10`, `median_executable_lines_review_below=50`; all are non-blocking. Do not turn them into CI gates without calibration.

## Initial code decisions (source inspected)

| Owner | Decision | Reason / verification |
| --- | --- | --- |
| `Loop/SelectLoop` | **Simplify**, implemented in `fc54592` | Replace full-queue `array_keys` allocation on every deferred tick with at-most-256 copied entries; eliminate two trivial private tick-reporting calls. Preserve callback ordering, cancellation, tick stop, timer lag and metrics; >256 regression added. |
| `Coroutine/Internal/FiberScheduler` | **Simplify**, follow-up | Inline the one-line high-water ready-queue recorder in two existing call sites; one fewer private method, identical measurement and admission logic. Existing scheduler-pressure and coroutine regressions remain authoritative. |
| `Coroutine/Internal/ReadyQueue` | **Retain** | Bounded `SplQueue`, unique admission set, controlled cancellation and FIFO lifecycle are real scheduler invariants. |
| `Network/Internal/ByteQueue` | **Retain** | Indexed chunks and compact-on-threshold already avoid `array_shift` on critical byte movement and enforce budget accounting. |
| `Loop/Internal/TimerQueue` | **Retain** | Heap and bounded due-timer processing are deliberate fairness and deadline invariants. |
| `Http/Http2/Internal/ResponseScheduler` | **Retain owner** | Flow control, per-stream fairness, wire pressure and frame budgeting are cohesive; one-line helpers should only be removed after changed-complexity and performance verification. |
| `Http/Client/Internal/Http3ClientAdmissionGate` | **Retain** | FIFO multiplex admission, GOAWAY semantics and request capacity enforcement represent a distinct independent lifecycle. |
| `Http/Client/Internal/Http3ClientPeerStreamType` | **Retain** | Its small one-method class holds fragmented QUIC stream-type decoder state across callbacks. Size is not a reason to merge. |
| `Http/Internal/ContentLengthParser` | **Retain** | Dedicated security-sensitive unsigned decimal/overflow parsing boundary, independently testable and used across HTTP paths. |
| `Network/Internal/StreamWriter` | **Retain** | Scoped `fwrite` warning containment, central failure policy; removing it would duplicate error handling. |
| `Runtime/Host/NativePhpResponseWriterFactory` | **Retain** | Real native PHP output adaptation and bounded response writing are a host-runtime boundary. |
| `Runtime/Host/HostDriverFactory` and `Loop/LoopFactory` | **Retain** | Real multiple-backend selection and capability/fallback contracts, not pass-through wrappers. |
| `Runtime/Host/DynamicHostObject` | **Retain** | Validated dynamic host invocation and normalized reflection-property access, real interoperability boundary. |
| `Runtime/Internal/WorkerDiagnosticsSampler` | **Retain** | Independently timed worker/loop metrics snapshot and report lifecycle. |
| `Network/Internal/ConnectionCallbackDispatcher` | **Retain** | Deterministic callback error aggregation and failure cleanup; not transparent delegation. |

No production class or file has yet been proven safely removable without losing a contract, isolation boundary or useful behavior. Do not delete one simply to improve a ratio.

## Next evidence-driven review batches

- **A — Runtime core:** event loops, coroutine Fiber scheduling, cancellation, timers, deferred queues, process executor, filesystem. Inspect per-invocation allocations, queue churn, cancellation and worker reset; verify fairness and leak bounds. Begin with recorded local exact-source PHPForge and representative GitHub worker benchmarks.
- **B — Network and protocol hot paths:** connection copy/queue churn, socket reads/writes, TLS, DNS/proxies, H1/H2/H3, QPACK, WebSocket. Verify independent protocol peers, small and large payloads, backpressure, p99, correctness and end-to-end successful RPM. Keep protocol-defined types and frame-state machines intact.
- **C — Hosts, supervisors and metrics:** worker restarts/recycling, native/portable/Swoole/FPM/RoadRunner/FrankenPHP adapters, optional extension capability detection, request-state cleanup and low-cost diagnostics. Avoid per-request reflection/configuration polling; only remove wrappers after inspecting all callers.
- **D — Structural matrix:** use installed PHPForge's supported architecture/class-complexity and duplicate detectors first; classify each small/one-method/pass-through candidate as meaningful boundary, safe inlining/merge, or blocked by consumers. A removal requires before/after test evidence, measured benefit where hot, and no public contract loss. Track file/class/method counts and changed paths in a future audit evidence record.
- **E — Release performance and risk:** close U15.10 only with independent real 1800s mixed HTTP/file/TCP/autoscale/GC/OPcache/whole-tree proof in **both** PHP8.4/8.5, zero application errors and no sampler coverage loss. Then U17.02–09 exact frozen-head QA, package/host/protocol interoperability and maintainer risk review.

## Existing release-blocking diagnostics

Historical real C4 mixed run [#40538869349](https://github.com/infocyph/Runwire/actions/runs/40538869349) failed on PHP8.4/8.5. Its process-tree sampler encountered disappearing/live child procfs metrics; PHP8.4 also observed nonzero HTTP errors. The separate [Release Certification #40549295417](https://github.com/infocyph/Runwire/actions/runs/40549295417) passed individual packaging, matched H1, HTTP-only 1800s, and release-guard jobs but the final gate correctly refused the **8 remaining OPEN tasks**. The successful HTTP-only soak is not a substitute for U15.10's mixed workload. Neither is a performance baseline that allows fabricated speedup claims.

## Acceptance and documentation discipline

Do not alter canonical checkboxes until required immutable QA proof succeeds. Tag each performance result with baseline SHA, candidate SHA, PHP/runtime/extensions, exact workload, successful RPM/RPS, p50/p95/p99, error/timeout counts, client CPU headroom, peak and steady RSS/FD and actual duration. Compare repeated medians against the predeclared workload-specific budget; do not quietly change Runwire's release threshold to match PHPForge's generic default. Historical W-series snapshots are archival, not current counters.

**Outcome of this first pass:** one deferred-batch allocation simplification and three total private-method removals across `SelectLoop` and `FiberScheduler`; no external API removals, no new production files, and no throughput uplift claimed without benchmarks. Full source-wide structural review and release certification remain ongoing work items, not marked complete.
