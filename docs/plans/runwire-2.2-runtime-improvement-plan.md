# Runwire 2.2 — runtime improvement and release plan

**Status:** DRAFT / planning only
**Updated:** 2026-10-19
**Base:** `main` @ `7e9bf20ad0737a187dfed77b723791b141b36378`
**Branch:** `plan/runwire-independent-ecosystem-roadmaps`
**Implementation:** NOT STARTED
**Release:** No tag, merge or publish authorized.

## Mission and product boundary

Improve the existing standalone `infocyph/runwire` **pure-PHP** process and network runtime on its own merits. The library remains framework-agnostic, installable through Composer, functional without Rust, Runwire-Core, or Runwire-Cells. The latter projects are completely independent products, **not required accelerators, substitutes, or components**. Avoid extension-detection logic for those packages, common ABI contracts, automatic fallback between products, or cross-repository release coupling.

Retain native and hosted runtime support: PHP CLI prefork and portable, FPM, FrankenPHP, RoadRunner, Swoole/OpenSwoole. Do not promise arbitrary blocking PHP I/O becomes asynchronous. PHP 8.4+ on 64-bit remains the baseline unless intentionally reconsidered.

## Baseline findings, 2026-10-19

- Latest verified tag: `2.1.2` (2026-10-17), main head `7e9bf20`.
- Existing native HTTP/1.1, HTTP/2 + HPACK, HTTP/3 + QUIC/QPACK, WebSocket HTTP/1.1, framed TCP/Unix, UDP.
- Native prefork with PCNTL/POSIX, portable single-process fallback, event loop using ext-event or SelectLoop; current SelectLoop native connection ceiling is 256.
- Structured Fiber coroutines, scope ownership, channels/futures/locks, deadlines, cancellation and bounded backpressure.
- Latest standard main CI lanes green when reviewed (security/standards, portable, Swoole, benchmarks, source audit); **long release-certification workflow conditional and not proven completed for this tag**.
- Existing release evidence includes PR #8 reporting 556 tests / 3654 assertions; these are reported results, not rerun by this plan.
- Repository code broadly organized under `src/Http`, `Coroutine`, `Loop`, `Runtime`, `Supervisor`, `Network`, `Process`, `Metrics`.
- QA and benchmark harnesses already exist. Review them rather than replacing them wholesale.

## Non-goals

- No Rust extension requirement, no Runwire-Core / Cells dependency, no PHP API proxy over either.
- No cluster consensus, actor durability, state replication or distributed cell placement.
- No ORM, routing, framework container, cache or messaging functionality; these belong to specialist consumers.
- No rewrite of proven HTTP components without a measurable, reproducible reason.
- No weakening protocol/resource limits or silently enabling unsupported runtime capabilities.
- No assumption that compatibility or legacy behavior is sacred: breaking changes require a documented decision and release strategy, not unconscious churn.

## Engineering principles

1. **Correctness before performance:** protocol semantics, security and lifecycle invariants remain mandatory.
2. **Profile first:** categorize CPU, allocation, copying, syscalls, scheduler delay and blocking time before optimization.
3. **Benchmark with parity:** pin hardware class, PHP and extensions, worker count, transport, TLS, payload, keepalive, concurrency and duration. Record RPS, p50/p95/p99, CPU, peak RSS, errors and fairness.
4. **Bounded execution:** connection count, per-tick work, buffering, task queues and deadlines stay explicit.
5. **Single owner of reactor/scheduler:** native worker owns native reactor; hosted modes respect host ownership.
6. **Persistent isolation:** reset must cover successful, exceptional, cancelled and deadline paths; worker-generation handles invalidated on replacement.
7. **No speculative micro-optimization:** A/B and revert if gain is below noise, causes tail regressions or adds unacceptable complexity.
8. **Every batch:** code -> focused tests -> complete relevant QA -> performance/fault evidence -> update tracker -> commit -> next batch. Do not claim success from skipped jobs.

## Program batches and tracker

| Batch | Scope | Exit criteria | Status |
|---|---|---|---|
| R0 | Baseline, invariant inventory and benchmark calibration | Golden measurements and matrix committed; existing test and workflow state audited | [ ] Not started |
| R1 | Request/lifecycle hot path | Lower allocation/copy overhead with isolation invariants preserved | [ ] Not started |
| R2 | Loop and scheduler | Wakeup/timer/ready-queue improvements with fair scheduling and zero runaway backlog | [ ] Not started |
| R3 | HTTP/1.1 and response throughput | Parser/write/buffering reductions with RFC and abuse regressions | [ ] Not started |
| R4 | HTTP/2 and HTTP/3 protocol optimization | Flow-control/fairness/cancellation parity, external interoperability | [ ] Not started |
| R5 | Coroutine-aware outbound I/O | Nonblocking networking primitives, real cancellation/deadline/backpressure behavior | [ ] Not started |
| R6 | Hosted adapters and process lifecycle | Swoole fork ownership, FrankenPHP/RoadRunner/FPM parity, reload and drain safety | [ ] Not started |
| R7 | Observability and security | Attribution of blocking/lag, accurate memory bounds, protocol fuzzing | [ ] Not started |
| R8 | Integration + certification | Foundation/Infbyte/Webrick smoke, 30-min soak, matched 5×180s benchmark, release guard | [ ] Not started |
| R9 | Release preparation | Changelog from previous tag, migration notes, pinned QA evidence, no blockers | [ ] Not started |

### R0 — establish truthful baseline

- Capture exact source SHA, PHForge version, PHP 8.4/8.5 NTS, extensions, kernel/CPU and OPcache.
- Document representative workloads: empty response, small JSON, streaming response, 64KiB and 1MiB, keep-alive, TLS, H2 multiplexing, H3, WebSocket, TCP/UDP, cooperative tasks; repeat warmed measurements.
- Identify top allocation and CPU hot spots, request/context/responder creation paths, scheduler queue and timer costs, socket I/O syscall patterns.
- Separate in-process microbenchmarks from real TCP/TLS and independent client measurements.
- Assert and pin protocol, cancellation, timeout, worker readiness, lifecycle reset, resource-budget and immutable context invariants.
- Capture external hosted-driver integration and actual worker-creation behavior.
- Compare release-certification trigger logic: conditional workflows must be invoked deliberately on the exact candidate. Publish baseline artifact with raw evidence and commands.

### R1 — request and lifecycle

- Profile `RequestContext`, `RuntimeApplication`, host normalizers, response-writer state, metric snapshots, resetter dispatch.
- Reduce duplicate normalization and redundant allocations only where ownership/immutability remain intact.
- Verify no request-local data survives reuse; single-use contexts reject reactivation; shutdown always resolves failures.
- Confirm worker-local factory construction for every Swoole/OpenSwoole replacement; avoid inherited pre-fork resources.
- Gate: no changed public behavior unless explicitly approved; performance improvement must survive repeated measurements.

### R2 — event loop and coroutines

- Profile `EventLoop`, `SelectLoop`, `FiberScheduler`, timer queue, ready queue, watcher lifecycle.
- Prioritize fairness and bounded per-tick work before average-case throughput.
- Exercise suspended cancellation, wait registration/unregistration, stale task generation, channel close, zero-capacity rendezvous, wakeup races and scope termination.
- Keep ext-event optional; portability fallback must remain correct and fail-closed for unsupported topologies.
- Gate: overload/soak evidence plus scheduler latency and memory footprint.

### R3/R4 — native protocol performance

- HTTP/1.1: request-head syntax, body stream admission, keepalive boundaries, TCP_NODELAY settings, chunk writes, zero-copy possibilities only when feasible.
- HTTP/2: HPACK compression limits, frame parsing, stream fairness, flow control, header-block completion and GOAWAY drain boundaries.
- HTTP/3: QPACK dynamic table/blocking handling, QUIC transport, request acceptance during drain, peer interop, response fairness and no 0-RTT app dispatch.
- Keep FIXED scheduling default; optimize AUTO only with complete transition matrix and p99/fairness evidence.
- Negative tests: malformed frames, oversized fields, rapid reset/close, slowloris, streaming backpressure, excessive stream/connection churn.
- Gate: RFC behavior and resource ceilings unaffected; independent client interoperability passed.

### R5 — outbound cooperative I/O

- Begin with TCP/TLS connection and readable/writable wait with explicit nonblocking sockets and cancellation.
- Provide DNS/HTTP transport only after confirming scoped ownership, timeout semantics and no implicit blocking work; dependencies may be optional separate adapters rather than mandatory code.
- Build a blocking-I/O visibility story for non-integrated PHP APIs; never claim fibers imply nonblocking DB calls.
- Test real network I/O, cancellation at every await, connection eviction, bounded buffers, and host reactor interaction.

### R6/R7 — robustness and diagnostics

- Race/fault tests for worker replacement, readiness, rolling reload, accepted but incomplete request admission, long-lived socket, failed reset, shutdown exceptions.
- Verify root/non-root privilege transition; CLI FPM/FrankenPHP/Swoole adapters must not compete for reactor ownership.
- Track loop lag, long callback duration, queue depth, cancellation counts, CPU/RSS (distinct from PHP allocator), admission/rejections and protocol errors.
- Fuzz parser boundaries and continuously test with independent HTTP/2 and HTTP/3 clients where available. Avoid expensive instrumentation in fast path when disabled.

### R8/R9 — certification and release preparation

- Run focused and complete PHPForge gates, PHP 8.4/8.5 matrix, optional-extension absence, portable-no-PCNTL and accelerated-ext-event lanes.
- Test hosted runtimes against real worker processes; Swoole & OpenSwoole both.
- Execute the **actual** release-certification workflow against candidate SHA: matched baseline/candidate 5 × 180-second trials with warmup, 30-minute soak, Infbyte consumer full tests, Foundation/Webrick bridge.
- Confirm no open high-severity protocol/security regressions; investigate all skips and failures, including flaky or timing-sensitive runs.
- Update docs, migration, changelog comparing immediate previous tag; plan-only branch never tags/publishes.

## Evidence and acceptance template (repeat for every batch)

| Item | Required entry |
|---|---|
| Batch | Rn |
| Base/candidate SHAs | Exact |
| Change summary | Why, and what did not change |
| Functional tests | Commands, counts, results |
| Integration/fault tests | Hosts, scenarios, outcomes |
| Benchmarks | Raw artifact URLs, workload, warmup, duration, trials |
| Measurements | RPS, p50/p95/p99, errors, CPU, RSS, memory budget |
| Risk review | Correctness, security, rollback |
| CI runs | Exact run URLs + skips/failures |
| Tracker update | Same commit as implementation evidence |

## Open decisions before implementation

1. Is the next PHP release specifically 2.2, or should a 2.1.x hardening release precede it?
2. Which workloads are the business-critical performance targets? Select primary and secondary, rather than benchmark cherry-picking.
3. Which existing contracts may intentionally break in a major version? No automatic BC promise.
4. What measurable regression budgets apply to p99 and RSS, alongside existing 5% sustained-throughput budget?
5. What is the minimum supported native QUIC build/ABI for the release matrix?

**This branch contains documentation only. All tracker boxes stay open until evidenced and committed.**
