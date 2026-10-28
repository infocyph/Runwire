# Runwire 3.0 — PHPForge engineering-principles performance and structural audit

**Review baseline:** branch `plan/runwire-independent-ecosystem-roadmaps`, draft PR #9, baseline source `2da0cd9edda10ddfe0ec5499cc3b45b4e2fad67f`, PHPForge principles `44244c2164bcbd6d995936785ebb56889ad72e46` (2026-10-28).
**Scope:** repository-wide production-source inventory and targeted ownership/hot-path inspection; subsequent proof requires exact-source PHPForge QA and GitHub-worker benchmarks. This is **not** a claim of a completed line-by-line review or production-measured performance improvement.
**Release ledger:** 159/167 QA accepted, 8 OPEN; C4 U15.10 and seven C5 U17 tasks remain OPEN. No task count inflation, merge, tag, external publishing, new repository, or threshold relaxation.

## W226 — PHPForge whole-tree technical signoff and C5 release-profile evidence

Source `13c3bdb09c9460b2b5feebea38e7d78ff14004f4` [Security & Standards #40653816760](https://github.com/infocyph/Runwire/actions/runs/40653816760) PASS includes both PHP versions stable/lowest, both static analyzers, Composer and native QUIC; [Source Audit #40653816490](https://github.com/infocyph/Runwire/actions/runs/40653816490) PASS. Previous complexity-related violations were corrected with cohesive bounded implementations (including supervisor `WorkerActivitySignals`, loop and ByteQueue) rather than raising project limits or consolidating unrelated classes. Independent H2 [#40653954379](https://github.com/infocyph/Runwire/actions/runs/40653954379) successful RPS +39.45% in **same PyPy benchmark profile**, both CV <2.5 and client CPU headroom, with strict byte validation; RFC6455 WebSocket [#40653980967](https://github.com/infocyph/Runwire/actions/runs/40653980967) PHP8.5 -4.577% stays within unchanged -5% gate; genuine 1800s both PHP versions [#40653969016](https://github.com/infocyph/Runwire/actions/runs/40653969016) passes zero errors, file/TCP/autoscale and process-tree/GC/OPcache. No blanket speedup claim outside measured cases. Technical signoff 167/167 with unchanged production code, no extra complexity threshold changes, documented unsupported features. Final handoff docs-only SHA must independently pass release gate. No merge/tag/publish.

## W224 — Verified exact-head mixed-soak closure, not a universal speedup

Original lifecycle saturation is no longer reproduced by the qualified [C4 PHP8.4/8.5 exact-source mixed 1800s #40641852591](https://github.com/infocyph/Runwire/actions/runs/40641852591) on `307f51ffe619d96777db3a724c16d630106a732f`: both PHP8.4/8.5 passed exact 1800s mixed HTTP/file/TCP/autoscale, complete whole-process and worker-GC/OPcache review, and zero HTTP errors. This is sufficient acceptance for U15.10 and C4 completion. The current 5×180s independent H2 comparator [#40646541373](https://github.com/infocyph/Runwire/actions/runs/40646541373) fails real client CPU headroom despite RPS/p99/CV passing: baseline median ~11,711 RPS and candidate ~11,585 RPS, client ~95–98% on both. Baseline and candidate must remain comparable; no performance/correctness claim may bypass a CPU-bound load generator. Current canonical scope 163/167, C4 11/11; four U17.06–09 OPEN, no release. Historical lower counts below are snapshots of earlier source.

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

## Rolling optimization batch and source review evidence (W219)

**Exact GitHub changes under draft PR #9:**
- `fc54592`: bounded SelectLoop deferred snapshot and direct tick diagnostics (2 private methods removed).
- `1074a1e`: direct FiberScheduler ready-depth high-water accounting (1 private method removed).
- `ac080af`: add reviewed FiberScheduler entry to the mandatory test-backed 2.1.2-to-3.0 implementation migration ledger after a correct fail-closed Source Audit.
- `22556f9`: ByteQueue fast path avoids allocating/intermediate joining arrays for one-chunk peek/read and preserves exact shared-byte-budget accounting; SelectLoop inlines single-use deferred dequeue (1 private method removed). `ByteQueueReadTest` and `SelectLoopTest` expanded.
- `d6b2e24`: EventLoop replaces O(read watcher count + write watcher count) cancellation scan with an indexed O(1) cleanup path. Additional indexed state is a justified space/time tradeoff; `EventLoopTest` covers reader/writer isolation, timed cancellation and re-registration. Removes 1 private method.
- This tracker revision adds the mandatory EventLoop migration disposition following Source Audit #40629676765. **Five private methods removed overall, zero production class/file deletions, no public contract removal.**

**Behavior and tradeoffs:** O(1) index *operations* describe algorithmic complexity; a real sustained-RPM gain is **not** implied without repeated exact-source benchmarks. The event loop's extra per-watcher index uses memory and must remain within memory/FD budgets. ByteQueue single-chunk fast path is source-level allocation avoidance; verify micro- and end-to-end throughput independently. The documented small-file byte-size signals are not executable-line measures and cannot support blanket consolidation. Keep ext-event optional and preserve select fallback. Do not treat any preceding in-progress/cancelled GitHub run as final QA or mark C4/C5 accepted.

## Existing release-blocking diagnostics

Historical real C4 mixed run [#40538869349](https://github.com/infocyph/Runwire/actions/runs/40538869349) failed on PHP8.4/8.5. Its process-tree sampler encountered disappearing/live child procfs metrics; PHP8.4 also observed nonzero HTTP errors. The separate [Release Certification #40549295417](https://github.com/infocyph/Runwire/actions/runs/40549295417) passed individual packaging, matched H1, HTTP-only 1800s, and release-guard jobs but the final gate correctly refused the **8 remaining OPEN tasks**. The successful HTTP-only soak is not a substitute for U15.10's mixed workload. Neither is a performance baseline that allows fabricated speedup claims.

## Final-head PHPForge remediation and baseline freeze notes (W220)

**Observed mandatory QA failures on `4d1b5b4`:** PHPStan `complexity.functionLike` for `ByteQueue::read()` 14 vs allowed 12 in PHP8.4/8.5 Analysis; Pint `ordered_class_elements` for `EventLoop.php` on all stable/lowest QA lanes. Source Audit, Sphinx, C4 migration, independent H2, portable native, hosted and subsystem concurrency checks passed on that same older source. C4 genuine mixed 1800s and C5 5-pair performance work was still running; neither is evidence for a newly changed source head. This batch repairs both detected rules by reusing `ByteQueue::discard()` for accurate single-chunk accounting and placing the new EventLoop index in Pint member order; no threshold change, suppression, acceptance inflation or premature claimed green. A corrected exact-head PHPForge run must confirm both repairs.

**Structural density conclusion at current scope:** 437 `src/*.php` files includes very many tiny but legitimate enums, exceptions, typed QPACK/HTTP/QUIC protocol frames, decoded section value objects, coroutine waiter state and lifecycle-bound server/listener pairs. Reviewed `BoundServer`, `BoundStreamServer`, `BoundDatagramServer`, `ChannelSendWaiter`, `PrimitiveWaiter`, `ReadyItem`, `ResponseStream`, QPACK result/value objects and `CheckedInteger`; all carry distinct typed invariants or wire-safety state. Do not merge these merely to satisfy the **non-blocking** under-40-executable-line or single-method matrix. The demonstrated worthwhile reductions are documented above; a full 437-file semantic review and measured RPM uplift remain unverified.

## W223 — Complexity-enforced supervisor lifecycle owner and measured root cause

Real mixed-soak artifact [#12793663954](https://github.com/infocyph/Runwire/actions/runs/40634940450/artifacts/12793663954) identifies a `WorkerContext` lifecycle socket backpressure crash after 760.587s and 15.2M requests with 16 wrong/incomplete HTTP responses; the original 1800s duration/correctness gate was **not** met. A B/I frame for every very short request is more expensive and less reliable than an eventually accurate state edge after 50ms of quiet. `c87f6c0` implemented an honest bounded signal stream with one timer and 50,000-cycle undrained-channel regression. The initial method/class complexity was **rejected** by the actual PHPForge analyzer (90/80 class, 16/12 method), so `44274f9` and `dc5c3f4` moved the genuine independent timer/busy/idle state machine to `Supervisor/Internal/WorkerActivitySignals`, reusing existing lifecycle error and cleanup contracts instead of waiving quality. This adds **one justified private type**, not multiple decorative wrappers, and never changes public API. **Runtime benefit unverified until new PHP8.4+8.5 full 1800s mixed and exact-source QA succeed.** Do not count 5×180s H2 #40635088787 and earlier H1 HTTP-only successes as U17.06 closure or a universal speedup claim. The trackers remain 162/167 with 5 open.

## W222 — Performance measurement integrity and generator allocation

The opt-in real C4 PHP8.5 mixed soak [#40630879058](https://github.com/infocyph/Runwire/actions/runs/40630879058) exposed incorrect equivalence between *requested* 1800s and *measured* elapsed on an early server failure. This was corrected in `d0d8c3c` without accepting the failed soak, inventing 1800-second resource samples, or relaxing the <85% H2 client CPU gate. The distinct real H2 comparison [#40629958832](https://github.com/infocyph/Runwire/actions/runs/40629958832) isolates independent hyper-h2 generator saturation (~92–94% client CPU, response correctness otherwise passing). Fixed-workload H2 header and expected-payload allocation can be eliminated without changing requests, bytes, stream concurrency or comparison policy; require new exact SHA CPU evidence before any speedup claim. Program currently 162/167 accepted, five open.

## W221 — C5 validated evidence under the PHPForge engineering principles

C5 U17.02 full PHPForge QA/analysis and U17.03 real independent protocol/host correctness and U17.05 packaging/optional-extension compatibility are accepted against frozen production code `9fa4d6ce03c000d61ae3a8f402aa6e5f9d332f8f`; see the canonical plan W221 and C5 risk register W221. The 437-file small-type inventory is **not** a justification for undocumented deletion. Five private methods were removed in audited optimizations, but no unsupported end-to-end speedup is claimed. The exact 5×180-second baseline/candidate performance and 1800-second full-process mixed soak U17.06/U15.10 are **OPEN**, as are final risk and maintainer handoff gates. No waivers or changes in acceptance thresholds.

## Acceptance and documentation discipline

Do not alter canonical checkboxes until required immutable QA proof succeeds. Tag each performance result with baseline SHA, candidate SHA, PHP/runtime/extensions, exact workload, successful RPM/RPS, p50/p95/p99, error/timeout counts, client CPU headroom, peak and steady RSS/FD and actual duration. Compare repeated medians against the predeclared workload-specific budget; do not quietly change Runwire's release threshold to match PHPForge's generic default. Historical W-series snapshots are archival, not current counters.

**Outcome of this first pass:** one deferred-batch allocation simplification and three total private-method removals across `SelectLoop` and `FiberScheduler`; no external API removals, no new production files, and no throughput uplift claimed without benchmarks. Full source-wide structural review and release certification remain ongoing work items, not marked complete.
