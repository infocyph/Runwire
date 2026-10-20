# Runwire 2.2 — runtime improvement and release plan

**Status:** ACTIVE — 69/95 canonical tasks checked; 7 R5 dialer tasks deferred beyond 2.2, leaving 19 in-scope open tasks; R8.04 network integration passed, full R6/R7/R8/R9 release certification still open
**Target release:** `2.2` (confirmed 2026-10-19)
**Previous published release:** `2.1.2`; the 2.2 release notes will compare directly against this tag unless another tag is published beforehand.
**Updated:** 2026-10-20
**Base:** `main` @ `7e9bf20ad0737a187dfed77b723791b141b36378`
**Branch:** `plan/runwire-independent-ecosystem-roadmaps`
**Implementation:** Core 2.2 improvements committed (host header normalization, SelectLoop timer fairness, ByteQueue batched read); all six standard workflows passed on `5b88cff2f408`; exact-SHA 30-minute soak and PHPForge release guard passed, but paired throughput variance failed the certification limit. The historical Infbyte Composer failure is external, not a Runwire-owned release gate; Runwire-only release-certification workflow replacement committed; exact-SHA QA and independent release acceptance remain outstanding.
**Release:** No tag, merge or publish authorized.

## Release decision

- **Confirmed target: Runwire 2.2.** Deliver the PHP-runtime improvement program as the next planned minor release; do not insert a speculative `2.1.x` hardening milestone.
- Scope is restricted to the existing standalone PHP Runwire library. Runwire-Core and Runwire-Cells retain their own independent versions and roadmaps.
- No release, merge, or tag until all applicable QA, benchmark, integration, and release-certification gates pass for the exact candidate SHA.
- If a release-blocking security or correctness issue requires an emergency patch, handle it explicitly as a separate maintainer decision; do not silently change the 2.2 target.

### Strict Runwire-only development and qualification boundary — maintainer decision, 2026-10-20

- **This plan authorizes changes only to `infocyph/Runwire`, on `plan/runwire-independent-ecosystem-roadmaps` and PR #9.** Do not edit, push, rebase, open PRs in, patch, upgrade, or publish `infocyph/Foundation` or `infocyph/Infbyte`. Do not change any other dependency or consumer repository under this plan.
- Foundation and Infbyte are independent projects. Their package versions, dependency graphs, release schedules, and failed Composer resolutions are **outside Runwire's ownership and must not block Runwire 2.2 development or release qualification**. Keep historical failures as truthful external observations, not as mandatory Runwire pass/fail criteria.
- No Foundation/Infbyte source clone, suite, bridge, alias, synthetic Composer version, or branch pin is required for Runwire's R6/R8/R9 acceptance. Use Runwire-owned fixtures, standalone Composer package install checks, supported real hosts, independent protocol clients, and self-contained smoke tests for Runwire release proof.
- **Runwire-only workflow implemented:** `.github/workflows/release-certification.yml` now replaces the former Infbyte job with independently Composer-installed Runwire native HTTP 200/400 smoke on PHP 8.4 and 8.5. The next **QA step** is proving this job and all other release checks on one exact SHA; no evidence is pre-certified.
- Runwire-owned correctness, PHP 8.4/8.5 quality, H1/H2/H3 interoperability, worker lifecycle, matched 5 × 180-second baseline comparison (CV < 2.5%), p99/RSS constraints, 30-minute soak, and release guard **remain mandatory**. No merge, tag or publication until those gates pass.
- This scope decision changes **acceptance requirements, not completion evidence**: leave R6.10, R8.05, R8.08 and R9 tasks unchecked until their revised Runwire-only requirements demonstrably pass.

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
| R0 | Baseline, invariant inventory and benchmark calibration | Golden measurements and matrix committed; existing test and workflow state audited | [~] 9/11 — audit complete; end-to-end A/B qualification in R8 |
| R1 | Request/lifecycle hot path | Lower allocation/copy overhead with isolation invariants preserved | [~] 7/8 — host fast path and lifetime QA; A/B trace guard outstanding |
| R2 | Loop and scheduler | Wakeup/timer/ready-queue improvements with fair scheduling and zero runaway backlog | [~] 8/9 — scheduler profiler/boundary verified; mixed-load soak open |
| R3 | HTTP/1.1 and response throughput | Parser/write/buffering reductions with RFC and abuse regressions | [~] 8/9 — HTTP1/ByteQueue regression suite; end-to-end A/B open |
| R4 | HTTP/2 and HTTP/3 protocol optimization | Flow-control/fairness/cancellation parity, external interoperability | [~] 6/10 — aioquic/ngtcp2 confirmed; workload saturation and storm stress open |
| R5 | Coroutine-aware outbound I/O | New TCP/TLS dialer is outside 2.2; existing `AsyncConnection` contracts documented | [↷] 2/9 recorded; R5.02–R5.08 deferred outside this release, not blockers |
| R6 | Hosted adapters and process lifecycle | Swoole fork ownership, FrankenPHP/RoadRunner/FPM parity, reload and drain safety | [~] 8/10 — worker-generation invalidation and privilege ordering verified; real cross-transport recycle/host gates open |
| R7 | Observability and security | Attribution of blocking/lag, accurate memory bounds, protocol fuzzing | [~] 9/10 — dependency advisories audited; final leak/soak measurements open |
| R8 | Runwire-only integration + certification | Standalone package/host smoke, 30-min soak, matched 5×180s benchmark, release guard | [~] 7/10 — real H1/H2/H3/WS/TCP/Unix/UDP and QA passed; full release certification remains open |
| R9 | Release preparation | Changelog from previous tag, migration notes, pinned QA evidence, no blockers | [~] 5/9 — clean PHP8.4/8.5 archive install certified; final release handoff open |

### Active release-scope and critical-path ledger — 2026-10-20

**Why progress appeared stalled:** Earlier progress updates focused repeatedly on R0's last benchmark checkboxes despite successful implementation work across R1–R7. The source branch has moved well beyond that earlier checkpoint. Do not use a stale R0/R1 snapshot as the total program status.

**Verified at source SHA `f804a5a6f6e7d9844dd18faedc15cba001bbc80f`:** [Security & Standards](https://github.com/infocyph/Runwire/actions/runs/39374716440), [Benchmarks](https://github.com/infocyph/Runwire/actions/runs/39374716128), [Portable Native](https://github.com/infocyph/Runwire/actions/runs/39374715960), [Swoole/OpenSwoole](https://github.com/infocyph/Runwire/actions/runs/39374715907), [Source Audit](https://github.com/infocyph/Runwire/actions/runs/39374716040), [Release Candidate](https://github.com/infocyph/Runwire/actions/runs/39374716170) all **succeeded**. Conditional [Release Certification](https://github.com/infocyph/Runwire/actions/runs/39374715989) was **skipped** and cannot be counted as passing.

| Accounting | Tasks |
|---|---:|
| Canonical original plan | 95 |
| Checked DONE with evidence | 64 |
| Explicitly deferred beyond 2.2 | 7 (R5.02–R5.08, still unchecked) |
| **Active 2.2 remaining** | **24** |
| **In-scope completion** | **64/88 (72.7%)** |

Do **not** check deferred R5 tasks or silently delete them. Their deferral is recorded in [R5/R6 handoff](evidence/runwire-2.2/r5-r6-handoff.md). R5 can be signed off as a *scope resolution* only; this is not a new nonblocking dialer release.

**R4.06 verified:** [Security & Standards #39374716440](https://github.com/infocyph/Runwire/actions/runs/39374716440) passed live aioquic 1.3.0 interop on PHP 8.4/8.5, independent ngtcp2 v1.25.0 / nghttp3 v1.18.0 client on PHP 8.5, OpenSSL >=3.5, ext-quic API/soak, and response-body validation. PHP 8.4 ngtcp2 job was intentionally skipped; do not present it as passed. See [R4 evidence](evidence/runwire-2.2/r3-r4-protocol-audit.md).

**QA-supported closure checkpoint (2026-10-20):** R2.01 scheduler task/wakeup/cancel/I/O profiling and hard overload cleanup verified in [benchmarks 39378520084](https://github.com/infocyph/Runwire/actions/runs/39378520084) on PHP8.4/8.5; R6.08 mock POSIX transition/partial failure acceptance Pest/Pint/analysis passed in [QA 39379164190](https://github.com/infocyph/Runwire/actions/runs/39379164190); R7.09 locked Composer audit found zero published security advisories with only a reported abandoned dev package; R9.05 [archive install 39379163941](https://github.com/infocyph/Runwire/actions/runs/39379163941) succeeded on PHP8.4/8.5 with safe AUTO defaults and no installed optional ext-event. Final SHA release certification/real-host soak remains R8/R9 and not implied by this checkpoint.

**Unblocked execution sequence (source work → focused QA → evidence, then next package):**

1. **P1 — Complete independently testable work:** focused R2 scheduler profiling, R4 H2/H3 codec profiling/interop, R6 real worker fault/privilege testing and R7 security dependency audit. Preserve correct behavior and avoid speculative fast-path changes.
2. **P2 — Consolidate actual performance proof:** R0.06/.07, R1.08, R2.09, R3.09, R4.01/.02/.04/.09, R7.08. Run real same-host baseline and candidate for **each changed hot path**, classify noisy results as inconclusive; no invented speedups. Reuse one pinned environment manifest and evidence protocol, rather than each batch reinventing benchmark setup.
3. **P3 — Independent hosted/protocol evidence:** R4.06, R6.02/.07/.08/.10, R8.04/.05 (including real client QUIC/OpenSSL ABI and worker restart). If external infrastructure is unavailable, keep its gate OPEN with an explicit test owner and installation condition.
4. **P4 — One immutable Runwire-only release candidate:** R8.02/.03/.05/.07/.08/.10 and R9.05–R9.09, full exact-SHA QA, 5×180s matched benchmark with CV < 2.5%, 30-minute soak, independent Runwire package/host smoke and release guard. Revise Runwire's certification workflow to remove the mandatory Infbyte consumer job. The historic unpublished Foundation dependency is outside this release's pass/fail criteria. No cross-repo changes, merge, tag or publication.

**Batch protocol:** We may prepare and QA independent source audits while one statistically unstable release benchmark is inconclusive, but may not close a task requiring that missing measurement. Avoid repeatedly triggering the 30-minute soak on documentation-only commits. At each code checkpoint use the exact-SHA regular CI suite, record red/skipped lanes, resolve Runwire-owned regressions before a subsequent hot-path change, and update the canonical checkboxes in the same development pass.

Detailed remaining-task register: [2.2 closure critical path](evidence/runwire-2.2/closure-critical-path.md).

### R0 — establish truthful baseline

**Current R0 status: 9/11 complete.** R0.01–R0.05 and R0.08–R0.11 have evidence; R0.06 actual end-to-end cost attribution and R0.07 stable same-host 2.1.2-to-2.2 concurrency curves remain open. The long release-certification process is tracked separately in R8; do not use an old CI smoke sample as full R0 completion.

**Initial evidence:** [R0 repository and QA preflight](evidence/runwire-2.2/r0-preflight.md), 2026-10-19. PR [#9](https://github.com/infocyph/Runwire/pull/9) opened as draft. Preflight and subsequent CI artifacts now establish the checked R0 audit and acceptance-policy tasks; see [R0 profile and manifest](evidence/runwire-2.2/r0-profile-and-manifest.md) for latest toolchain, risk and measurement provenance. The final same-host profiler/concurrency tasks remain open. Latest standard CI is green at `f804a5a6`; its release-certification job was skipped.

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
- Execute the **Runwire-only** release-certification workflow against candidate SHA: matched baseline/candidate 5 × 180-second trials with warmup, 30-minute soak, standalone Composer consumer and real-host smoke owned by Runwire, and PHPForge release guard; Foundation/Infbyte integration is not a release gate.
- Confirm no open high-severity protocol/security regressions; investigate all skips and failures, including flaky or timing-sensitive runs.
- Update docs, migration, changelog comparing immediate previous tag; plan-only branch never tags/publishes.


## Detailed implementation specification and delivery contract

This section converts the R0–R9 roadmap into executable engineering packages. Each checkbox is an **unstarted planned task**; mentioning a source file identifies a review or likely implementation site, not a confirmed defect. Final changes must be based on profiling, reproduced failures and the current source. The already-existing batch descriptions above remain the scope summary; the task IDs below are the canonical per-batch tracker.

### Release scope, change control and dependency graph

- Release target: **2.2**; base tag: **2.1.2**; no Rust, Runwire-Core or Runwire-Cells dependency; preserve the pure-PHP/Composer delivery model.
- Desired outcome: highest sustainable **successful requests per minute (RPM)** on representative production-equivalent workloads, subject to all protocol, security, fairness, memory and latency constraints. Convert successful RPS to RPM using 60 × RPS; do not include invalid, incomplete, timed-out or failed requests.
- Performance improvements are not presumed defects in existing code. A measured bottleneck, complexity reduction, or demonstrated correctness issue must justify every behavior-sensitive change.
- Plan change policy: only one active implementation batch at a time; if a blocking issue requires expanding scope, update this plan and its tracker **before or in the same commit as** the corrective work. Never silently skip a batch, change a baseline, or mark a step finished because its CI job was skipped.
- Suggested ordering: R0 -> R1 -> R2 -> R3 -> R4 -> R5 -> R6 -> R7 -> R8 -> R9. A regression-fix detour may return to a previous batch, but its tracker and evidence must reflect that interruption.
- Prioritize simple direct hot paths, optimized immutable configuration and optional instrumentation. Do not introduce pools, memoization, redundant abstractions, compatibility wrappers or new packages without lifecycle proof and measured request-level value.
- No repository other than `infocyph/Runwire` may be changed under this plan: expressly no Foundation or Infbyte code, metadata, branches, dependency changes or releases. Their external failures are archival observations and not Runwire blockers. Only Runwire-owned certification tests are mandatory; do not modify dependent projects to obtain green CI.
- 2.2 is a minor release: preserve intended public/protected contracts by default. If a breaking change proves essential, record the affected symbols, migration, rationale and explicit decision before implementation; do not hide a major incompatibility in a minor release.

### Definition of done for **every** batch

1. Precondition reviewed: base commit and previously closed batch QA status are recorded.
2. Each task has a reproducible issue, selected design, touched-file list and success/failure behavior.
3. Implementation commit contains only scoped changes and the updated checkbox tracker.
4. Focused tests cover normal, malicious, timeout/cancellation, failure/cleanup and boundary cases where applicable.
5. PHPForge format/static/quality/test lanes and appropriate real-host or protocol tests pass; a green unit suite does not substitute for a required interoperability test.
6. A/B performance evidence exists for every hot-path change; include complete production-like successful RPM, tail latency, CPU, RSS, queues and error counts.
7. CI is checked against exact commit; all failing, cancelled, skipped or flaky required jobs are explicitly resolved and not counted as passes.
8. Regression or instability means fix in the **same batch**, rerun its gates and update the tracker; only then start the next batch.
9. Where performance is within noise, prefer simpler code and record a **no-change / rejected optimization** result as a valid closed task.
10. At release preparation, carry forward all outstanding blocking items instead of relabeling unfinished work as complete.

### QA execution and evidence workspace

Existing sources of truth:
- Test configuration/commands: Composer + PHPForge; first verify available scripts and their exact semantics from the installed toolchain. The project's existing entry points include composer ic:ci, composer ic:process, composer ic:release:guard, composer ic:benchmark and the repository PHPBench/Pest scripts.
- GitHub workflows: .github/workflows/security-standards.yml, benchmarks.yml, portable-native.yml, swoole-coroutine.yml, source-audit.yml, release-candidate.yml, release-certification.yml.
- Benchmark scripts: benchmarks/matched_release_certification.sh, http1_sustained_bench.php, adaptive_promotion_matrix.php, regression_compare.php, sustained_summary.php, comparative_evidence.php, opcache_footprint.php, websocket_evidence.php, transfer_compare.php.
- Suggested persisted evidence location: docs/plans/evidence/runwire-2.2/ for compact human-readable manifests, with raw large logs/JSON as linked CI artifacts; avoid committing massive samples to git.
- Store for each batch: base/candidate SHA, environment manifest, exact commands, correctness counters, warmup/trial duration, p50/p95/p99, **successful RPM**, CPU, RSS, memory/queue pressure, regression comparator result and CI run URLs. Redact tokens, private paths and secrets.

Preflight examples (not substitutes for their workflow-specific configurations):
1. composer validate --strict --no-check-publish
2. composer install
3. composer ic:ci
4. composer ic:release:guard (release gate; prerequisites must be satisfied)
5. vendor/bin/phpbench run --report=aggregate --output=json --progress=none --bootstrap=vendor/autoload.php --config=vendor/infocyph/phpforge/resources/phpbench.json benchmarks
6. Execute native listener and real-client acceptance using the repository's existing benchmark/integration scripts with explicit environment and correct worker topology.
7. Use the GitHub Actions artifacts and job conclusions to certify exact candidate SHA, **not** only the status badge.

### R0 — baseline, correctness inventory, toolchain audit

**Current measured evidence:** [R0 source provenance, relative microbenchmark candidates, H1/H2 concurrency curves and release safety policy](evidence/runwire-2.2/r0-profile-and-manifest.md). R0.01 (complete environment freeze), R0.06 (end-to-end hotspot attribution), and R0.07 (stable, warmed matched concurrency curves) are explicitly open. R0.10 is complete for acceptance-policy **definition and focused tests**, not a release pass. QA at commit `82698e55`: four PHPForge Pest/analysis lanes passed; real HTTP/3 PHP8.4 and OpenSwoole8.4 checks produced intermittent failures (OpenSwoole passed on authorized job rerun). Any QA failure keeps the release blocked.

**R0.03 CI equivalence:** This development environment has PHP 8.4 but lacks a Composer-installed vendor tree and direct repository network access. The existing four PHPForge Pest/Pint/analysis lanes, PHP 8.4/8.5 protocol benchmarks, portable native, accelerated ext-event, QUIC and the four Swoole-family real-host suites all **passed at the same source commit `d4bfad4f96d003ae753ad1c3e5512bd70e71e1ff`** (workflow IDs and skip matrix in [R0 evidence](evidence/runwire-2.2/r0-preflight.md)). This is a verified full **CI** QA execution, not a fabricated local run. Developer-local QA remains optional as an extra check for this batch; final cert must execute in actual CI.

**Goal:** Make later changes measurable and prevent improvements that win on an irrelevant microbenchmark while losing real successful RPM.

**Likely sources:** composer.json, README.md, docs/benchmarks.md, docs/architecture.md, docs/deployment.md, docs/security.md, every .github/workflows/*.yml, benchmarks/*, tests/*.

**Tasks**
- [x] **R0.01** Freeze reference: 2.1.2 tag SHA, current 2.2 branch SHA, Composer lock/dependency mode, PHP 8.4/8.5, extension versions, OPcache mode, OS/kernel/CPU and cgroup limits. Include the exact git diff and confirm a clean baseline.
- [x] **R0.02** Audit package dependencies, capabilities and selection: native prefork PCNTL/POSIX, native portable without PCNTL, ext-event vs SelectLoop, TLS/ALPN, ext-quic/HTTP3, FPM, FrankenPHP, RoadRunner, Swoole and OpenSwoole; distinguish supported, optional and non-applicable.
- [x] **R0.03** Run the existing full local test/analysis quality suite and enumerate skipped tests, optional extension unavailable cases and warnings. Investigate current red baseline rather than attributing it to future changes.
- [x] **R0.04** Inventory public/protected PHP API signatures, documented constructor defaults, exception/fail-closed behavior, capabilities, config policies and known contract limitations; record what 2.2 must retain.
- [x] **R0.05** Define workload matrix: HTTP1 plaintext/keepalive, TLS H1, H2 multiplexing, H3/QUIC, JSON, 64KiB/1MiB transfer, chunk streaming, WebSocket sustained, framed TCP/Unix, UDP, coroutine task/channel workload, worker reload under load, and representative consumer request.
- [ ] **R0.06** Profile realistic steady-state and cold paths, separating application handler, framework bootstrap, Runwire request context, HTTP parser, response writer, socket/syscall, event loop and Fiber scheduler costs. Rank only verified bottlenecks by end-to-end impact.
- [ ] **R0.07** Establish repeated, warmed concurrency curves and compare **successful RPM**, p50/p95/p99, errors, CPU, PHP allocator, process RSS, queues, FD count and cancellation/response failures. Confirm the load generator is not saturated.
- [x] **R0.08** Validate benchmark correctness accounting and baseline equivalence: response shape, TLS, worker count, OPcache, CPU limits, payload sizes, active extensions, client reuse and trial timing. Keep raw evidence and hashes.
- [x] **R0.09** Inspect release-certification.yml baseline currently pinned to pre-consolidation commit dc78bdc9.... For 2.2, define **published 2.1.2** as the primary immediate predecessor comparison; retain the historical baseline as a separately labeled optional trend only. Do not rewrite this until its reproduction data and workflow effects are known.
- [x] **R0.10** Record all baseline risks and acceptance budgets (minimum: no invalid responses or protocol violations, zero uncontrolled queue/memory growth; existing 5% throughput budget where applicable; explicit p99/RSS budgets chosen based on baseline variance). Do not invent absolute target numbers before measurements.
- [x] **R0.11** Create evidence manifest and tracker update showing exact baseline status. Commit it before initiating R1.

**Tests/evidence:** Full PHPForge matrix; tests/RuntimeTest.php, RuntimeSelectorTest.php, RuntimeCapabilityResolverTest.php, HttpProtocolSemanticParityTest.php, BatchPSoakTest.php; existing benchmarks and optional-host suites as available.

**Exit:** Reproducible baseline, documented constraints, known red/skip matrix and ranked hot spots. If the environment cannot reproduce production topology, document a required CI or dedicated-machine job instead of accepting a speculative performance result.

### R1 — request dispatch, lifecycle and allocation economy

**R1 functional implementation/audit: 7/8 complete (R1.01–R1.07); R1.08 release-grade matched A/B and tracing acceptance remains open under R8.** Four new focused regressions were added in `tests/R1RequestLifecycleRegressionTest.php`; existing activation/finalizer tests cover other required branches. Exact-source PHPForge Security & Standards QA [39238439296](https://github.com/infocyph/Runwire/actions/runs/39238439296) passed all PHP 8.4/8.5, stable/lowest Pest, Pint and analysis checks at source commit `ca1eb4c427094c462858cad01e8ce69004f64633`. This is **R1 preparatory regression coverage**, not a measured optimization or full R1 closure. The R0.06/R0.07 outstanding release-grade attribution and stable A/B qualification are explicitly carried into R8 promotion; do not declare speed improvements from the R1 change without that evidence. This explicitly isolates correctness-test preparation from the one-active-batch implementation rule.

**Likely sources:** src/Runtime/Host/RuntimeApplication.php, src/Runtime/Internal/RequestFinalizer.php, src/Runtime/RequestResetterRegistry.php, src/Runtime/Host/HostRequestFactory.php, src/Http/HttpRequest.php, src/RequestContext.php, src/Metrics/RuntimeMetrics.php, src/Http/Internal/CallbackResponseWriter.php.

**Tasks**
- [x] **R1.01** Profile native and hosted request construction, headers normalization, request-ID allocation, deadline creation, body representation and response callback assembly; rank avoidable work by requests-per-second frequency.
- [x] **R1.02** Remove demonstrably redundant conversions/normalization or repeated immutable config parsing without changing input validation, authorization assumptions or public response semantics.
- [x] **R1.03** Optimize completion/finalizer/reset path only after proving exactly-once response terminal transition and cleanup for success, handler throw, deadline, transport abort and explicit cancellation.
- [x] **R1.04** Audit exception aggregation when handler, reset and shutdown all fail; preserve primary failure and actionable diagnostics without leaking response or request-local state.
- [x] **R1.05** Recheck stable RuntimeContext vs single-use RequestContext ownership; reject double activation and inherited/reused mutable request fields.
- [x] **R1.06** Compare cold, first-use, and warmed persistent worker request costs; ensure optimizations do not shift expensive initialization into unsuspecting per-request paths.
- [x] **R1.07** Add focused regressions for null-valued request attributes, attribute-count limits, request IDs, cancellation completion, finalizer reentrancy and double close.
- [ ] **R1.08** Run matched native HTTP and hosted-adapter performance trials with tracing enabled/disabled; no request throughput penalty hidden by isolated lifecycle benchmarks.

**Existing test candidates:** tests/ApplicationLifecycleTest.php, RequestContextTest.php, RequestContextActivationTest.php, PersistentRuntimeIsolationTest.php, PersistentStateIsolationAcceptanceTest.php, ResponseSemanticsTest.php, HostRuntimeTest.php.

**Exit:** Equivalent request/response behavior, verified request isolation, no reset/finalization leaks, documented measured wins or rejected changes.

### R2 — event loop, scheduler and synchronization

**Likely sources:** src/Loop/EventLoop.php, src/Loop/SelectLoop.php, src/Loop/LoopFactory.php, src/Loop/Internal/TimerQueue.php, src/Coroutine/Internal/FiberScheduler.php, ReadyQueue.php, Suspension.php, src/Coroutine/CoroutineRuntime.php, CoroutineScope.php, Channel.php, Future.php, TaskLocal.php.

**Tasks**
- [x] **R2.01** Measure scheduler per-spawn, resume, cancel, sleep/wake and wait registration costs under low, medium and overload pressure; include allocated bytes and peak ready backlog.
- [x] **R2.02** Review EventLoop and SelectLoop timer/watcher lifecycle, stale registration removal and callback dispatch fairness. Verify no double wake, lost wake, reentrant resume or watcher leak.
- [x] **R2.03** Audit timer accuracy, ordering, clock-monotonicity assumptions and cancellation races, including task cancellation just before/after readiness.
- [x] **R2.04** Inspect queue data structures, primitive waiters and wakeup scheduling; simplify or optimize only demonstrated hot paths while retaining resource ceilings.
- [x] **R2.05** Verify parent/child scope completion, fail-fast sibling cancellation, collect-all errors, task-local snapshot inheritance and background scope generation ownership.
- [x] **R2.06** Validate bounded channels including capacity 0, full/empty/close contention, rejected send, multiple waiting senders/receivers; test synchronization owner rules and barrier break behavior.
- [x] **R2.07** Verify ext-event backend at high descriptor counts, SelectLoop ceiling of 256 Runwire-owned connections and native portable fallback without PCNTL/POSIX. No platform downgrade or misleading capability advertisement.
- [x] **R2.08** Exercise I/O wait under repeated socket close, invalid descriptor, canceled task, slow peer and nested coroutine group; ensure no deadlock or busy-spin.
- [ ] **R2.09** Run sustained mixed timer/network/CPU tests with fairness and p99, proving stable queues and bounded memory as throughput increases.

**Existing test candidates:** tests/EventLoopTest.php, SelectLoopTest.php, BatchLCoroutineSchedulerTest.php, BatchMStructuredConcurrencyTest.php, BatchNSynchronizationTest.php, CoroutineHardeningTest.php, AsyncConnectionTest.php, MonotonicTimeTest.php.

**Exit:** Correct wakeup/ownership semantics, performance evidence on both event-loop backends, no starvation and no unbounded scheduler growth.

### R3 — HTTP/1.1, transport buffers and WebSocket performance

**Likely sources:** src/Http/Http1/Http1Connection.php, Http1ResponseWriter.php, Internal/Http1Input.php, RequestHeadValidator.php, src/Network/Connection.php, Internal/ByteQueue.php, ByteBudget.php, StreamWriter.php, src/Http/ResponseTransfer.php, src/WebSocket/*, src/Runtime/Internal/NativeHttpWorker.php.

**Tasks**
- [x] **R3.01** Measure HTTP/1 request-head scanning, header validation, chunk/body decode, keepalive reuse and response-write allocations; isolate handler-independent and end-to-end costs.
- [x] **R3.02** Optimize byte queue compaction/write batching only after testing partial reads/writes, boundary fragmentation, backpressure and slow clients; avoid hidden payload copies and large retained buffers.
- [x] **R3.03** Check pipelining/connection reuse, request admission during worker drain, incomplete headers, 100-continue, method/status-specific response body semantics, HEAD and connection close behavior.
- [x] **R3.04** Validate TLS handshakes and ALPN selection, close-notify/error behavior, no plaintext downgrade and correct application-visible scheme/headers.
- [x] **R3.05** Verify ResponseTransfer chunk sizes, cooperative cancellation, streaming source ownership, writable wait and no uncontrolled memory growth.
- [x] **R3.06** Exercise WebSocket handshake/origin enforcement, frame parser masking, fragmentation/control frames, close codes, send backpressure and worker draining on live sessions.
- [x] **R3.07** Evaluate FIXED/LATENCY/THROUGHPUT/AUTO HTTP/1 scheduling and explicit TCP_NODELAY override under small interactive and sustained bulk responses; avoid global AUTO default change without evidence.
- [x] **R3.08** Add malicious traffic matrix: slowloris, oversized/duplicate/conflicting headers, invalid transfer lengths, forbidden trailer/state transitions, fragmented upgrade, half-open sockets, bursts of reconnects.
- [ ] **R3.09** Compare successful RPM, p99, CPU/RSS and socket bytes/write syscalls across plaintext/TLS, sizes, keepalive/close and low/high connection count.

**Existing test candidates:** tests/Http1ConnectionTest.php, Http1AbuseAcceptanceTest.php, Http1BackpressureTest.php, Http1WorkerBudgetTest.php, Http1AdaptiveConnectionSamplerTest.php, WebSocketIntegrationTest.php, WebSocketFrameParserTest.php, ResponseTransferTest.php, ResponseTransferTlsTest.php.

**Exit:** No protocol regression, all resource budgets intact, proven improved or unchanged real-server throughput and tail latency.


### R3/R4 batch QA (2026-10-20)

[R3 / R4 protocol audit](evidence/runwire-2.2/r3-r4-protocol-audit.md) captures 8/9 R3 tasks after focused source QA, and 5/10 R4 protocol-correctness audit tasks. R3.09 and R4.01/.02/.04/.06/.09 remain actual promotion/interop work, **not checked as completed**. Final release certification is still independent of normal CI.
### R4 — HTTP/2, HTTP/3 and adaptive protocol behavior

**Likely sources:** src/Http/Http2/Http2Connection.php, Hpack/*, Internal/FlowController.php, ResponseScheduler.php, src/Http/Http3/Http3Session.php, Qpack/*, Quic/PhpQuicHttp3Worker.php, Internal/*, src/Http/Internal/AdaptiveLoadController.php, src/Http/AdaptiveProtocolPolicy.php.

**Tasks**
- [ ] **R4.01** Baseline real H2/H3 multiplexed response throughput, streams/connection, dynamic-table pressure, header block sizes and flow-control stalls using independent real protocol clients.
- [ ] **R4.02** Profile H2 HPACK integer/huffman paths, header validation, frame parsing, data scheduling and output buffer churn; change only hot paths with mirrored encode/decode and malformed-input tests.
- [x] **R4.03** Validate H2 per-stream vs connection cancellation, fair scheduling, window updates, SETTINGS, GOAWAY and admission of already accepted incomplete header blocks when draining.
- [ ] **R4.04** Profile H3 QPACK dynamic table/blocking/unblocking, varint/frame codec and QUIC poll/write budgets; retain no application 0-RTT dispatch.
- [x] **R4.05** Validate H3 request-stream reset, header decoding completion during drain, control stream limits, cancellation, peer unidirectional streams and graceful bounded shutdown.
- [x] **R4.06** Perform independent interop with available ngtcp2/aioquic clients and supported PHP 8.4/8.5 QUIC/OpenSSL combination; record protocol version and extension ABI.
- [x] **R4.07** Revalidate adaptive FIXED baseline plus all AUTO crossover/transition promotion cases; no promotion based solely on average throughput or microbenchmark wins.
- [x] **R4.08** Fuzz framing, HPACK/QPACK invalid indexes/overflows, oversized stream counts, blocked sections, invalid SETTINGS and decompression limits with memory ceilings.
- [ ] **R4.09** Test slow receiver, many simultaneous streams, stream cancellation storms, stalled flow-control and mixed H1/H2/H3 listeners under rolling recycle.
- [x] **R4.10** Preserve end-user unified HttpRequest and ResponseWriterInterface semantics across three HTTP versions.

**Existing test candidates:** tests/Http2ConnectionTest.php, Http2AbuseTest.php, HpackTest.php, Http3SessionTest.php, QpackTest.php, Http3AbuseFaultAcceptanceTest.php, HttpProtocolSemanticParityTest.php, AdaptivePromotionMatrixTest.php, AdaptiveProtocolTransitionTest.php.

**Exit:** Real H2/H3 interoperability and fault validation pass, protocol-local fairness/backpressure preserved and performance claim reproducible.

### R5 — coroutine-aware outbound network I/O (bounded feature slice)

**2.2 scope decision:** New nonblocking TCP/TLS dialer, DNS and HTTP transport are explicitly **DEFERRED to a separately planned extension**, not a 2.2 release gate. R5.01 and R5.09 document the existing `AsyncConnection` contract and its blocking limitations; tasks R5.02–R5.08 remain unchecked as not implemented. [Decision and R6 parity audit](evidence/runwire-2.2/r5-r6-handoff.md).

**Scope rule:** Start with the existing coroutine/network abstraction; do not build a full HTTP client, DNS subsystem or database client just because it might be useful. Large API additions require a reviewed design gate; feature may be explicitly deferred if it cannot meet the 2.2 timeline without weakening guarantees.

**Likely sources:** src/Coroutine/Network/AsyncConnection.php, src/Coroutine/CoroutineScope.php, src/Coroutine/Internal/FiberScheduler.php, src/Network/Connection.php, src/Loop/LoopInterface.php, docs/coroutines.md.

**Tasks**
- [x] **R5.01** Review existing AsyncConnection operations and limitations; specify what remains blocking in PHP stream/DNS/TLS integration. Record exact user-visible promises.
- [ ] **R5.02** Design minimal public contract for nonblocking TCP connect/read/write using loop readiness and bounded memory; prefer extending an existing contract rather than parallel APIs.
- [ ] **R5.03** Bind connection/socket/waiter lifetime to owning request or worker scope; cancellation and deadline must close or reliably detach stale readiness registrations.
- [ ] **R5.04** Define partial read/write, EOF, peer hangup, timeouts, backpressure and retry/close semantics; avoid implicit infinite buffering.
- [ ] **R5.05** If TLS connect is included, verify certificate validation, timeout, handshake state and no synchronous execution surprise; otherwise explicitly document unsupported operation.
- [ ] **R5.06** Test real local TCP servers under many concurrent Fiber tasks, late/early cancellation, socket reuse, back-to-back close, full write buffers and slow peer.
- [ ] **R5.07** Probe Swoole/OpenSwoole host reactor integration and no competing event-loop ownership; do not claim every third-party blocking PHP API is awaitable.
- [ ] **R5.08** Benchmark I/O overlap and request-level benefit against equivalent sequential I/O; ensure no worse CPU/RSS/latency under overload.
- [x] **R5.09** Document interoperability limits, valid host modes and cancellation guarantees. Any deferred HTTP/DNS/DB integration must be listed as future scope, not checked as complete.

**Existing test candidates:** tests/AsyncConnectionTest.php, BatchORuntimeIntegrationTest.php, BatchPRaceFaultTest.php, BatchPSoakTest.php, CoroutineHardeningTest.php.

**Exit:** Small, safe, demonstrated networking improvement or an evidence-backed deferral approved in the tracker; no implied support for arbitrary async PHP calls.

### R6 — native supervisor and hosted-runtime lifecycle parity

**R6 audit progress: 8/10 tasks completed** from native, hosted, worker/portability and verified worker-generation QA. Only multi-protocol live recycle and complete standalone Runwire real-host matrix remain open (R6.02 and R6.10); see [R5/R6 evidence](evidence/runwire-2.2/r5-r6-handoff.md).

**Likely sources:** src/Runtime.php, src/Runtime/Driver/{FpmDriver,FrankenPhpDriver,RoadRunnerDriver,SwooleDriver}.php, src/Runtime/Host/RuntimeApplication.php, src/Runtime/Internal/{NativeHttpWorker,PortableNativeRuntime,WorkerRecycleState}.php, src/Supervisor/*.

**Tasks**
- [x] **R6.01** Inventory ownership matrix for listener, event loop, worker pool, application instance and request cleanup across every driver; enforce capability-first semantics.
- [ ] **R6.02** Test native prefork boot/readiness, crashed worker replacement, restart backoff, rolling reload, manual recycle and stop under active HTTP1/H2/H3/TCP/UDP traffic.
- [x] **R6.03** Test portable runtime with truly absent PCNTL/POSIX: one process only, no fictitious worker controls, explicit rejection of prefork-only settings.
- [x] **R6.04** Confirm Swoole and OpenSwoole real two-worker/worker-replacement factory runs **after fork** and reports child PID, worker slot and fresh context/metrics; run both serve() and serveApplication().
- [x] **R6.05** Confirm FPM request-scoped and FrankenPHP classic/worker/RoadRunner hosted boundaries do not attempt to bind a competing listener or drive a second incompatible event loop.
- [x] **R6.06** Exercise accepted-but-incomplete HTTP header admission during drain (H1/H2/H3), wait deadlines, keepalive stop, GOAWAY and WebSocket close; never drop already accepted requests incorrectly.
- [x] **R6.07** Verify worker-generation invalidation for active coroutine scopes, callback handles, application references, request contexts and timers after replacement. **Verified:** [WorkerGenerationIsolationTest](https://github.com/infocyph/Runwire/blob/plan/runwire-independent-ecosystem-roadmaps/tests/WorkerGenerationIsolationTest.php), [code d9117e7](https://github.com/infocyph/Runwire/commit/d9117e732e2f8a17602fed17273528330eb15d22), [PHPForge PHP8.4/8.5 QA 39429653975](https://github.com/infocyph/Runwire/actions/runs/39429653975); real multi-transport process reload still R6.02.
- [x] **R6.08** Verify privilege drop: supplementary groups, GID-before-UID, final identity check, non-root warning and startup rejection on partial transition.
- [x] **R6.09** Check host request/body/response fidelity, reset on every exception, and one drain/shutdown per instance including startup failure.
- [ ] **R6.10** Run real Runwire-host acceptance on PHP 8.4/8.5, including standalone Composer-installed Runwire fixtures; verify supported worker/process boundaries rather than fake adapters alone. Document unavailable infrastructure. No Foundation or Infbyte repository dependency.

**Existing test candidates:** tests/SwooleDriverTest.php, SwooleLoopTest.php, RoadRunnerRuntimeTest.php, GenericRuntimeTest.php, SupervisorLifecycleTest.php, NativeWorkerLifecycleTest.php, SupervisorRecycleTest.php, PrivilegeDropOrderingTest.php, PortableNativeRuntimeTest.php, BatchGDrainTest.php.

**Exit:** No orphaned workers, duplicated reactor owner, leaked request state or stale worker-generation work; external host acceptance proven.

### R7 — observability, threat models, fuzzing and process safety

**Audited 8/10 tasks** against existing CI-tested diagnostics, process safety, protocol abuse and fault suites. Remaining final soak and security advisory signoff are R8 promotion gates. [R7/R8 audit](evidence/runwire-2.2/r7-r8-qa.md).

**Likely sources:** src/Metrics/RuntimeMetrics.php, RuntimeMetricsSnapshot.php, DiagnosticsPolicy.php, src/Runtime/Internal/WorkerDiagnosticsSampler.php, src/Network/ConnectionLimits.php, src/Process/ProcessRunner.php, src/Control/ControlServer.php, docs/security.md.

**Tasks**
- [x] **R7.01** Enumerate low-overhead diagnostics already available: throughput/latency attribution, event-loop max lag, callback overruns, scheduler backlog, waiters, protocol control/errors, admission and worker churn.
- [x] **R7.02** Expose missing indicators **only if needed** for incident diagnosis and measurable overhead remains low; diagnostics disabled remains a cheap path.
- [x] **R7.03** Distinguish PHP allocated memory, actual process RSS/native extension memory, peak cgroup/container usage and FD/socket count; do not describe allocator-only recycle threshold as full RSS protection.
- [x] **R7.04** Review ProcessRunner absolute executable validation, shell bypass, allowlist, argv/environment/stdin/output limits, timeouts and process-group termination; test attacker-controlled input and no-PCNTL operation.
- [x] **R7.05** Review control endpoint access, socket/file permissions, privilege changes and shutdown capabilities; document safe default deployment.
- [x] **R7.06** Add fuzz/property/abuse tests for all HTTP versions, WebSocket frame validation, line/length-framed codecs, UDP oversized datagrams and TLS failure paths.
- [x] **R7.07** Fault-inject slow peers, connection floods, half-open streams, callbacks throwing during dispatch, cancelled request scopes, worker kill -9 and malformed protocol sequences.
- [ ] **R7.08** Run long soak against persistent application resets, PHP garbage collector/OPcache behavior, RSS drift and retained references; distinguish leak from deliberate cache growth.
- [x] **R7.09** Security audit of exposed surfaces and third-party packages, plus dependency advisories; record vulnerabilities explicitly without weakening requirements to get green CI.
- [x] **R7.10** Update runbook with exact gauges for event-loop lag, blocked callbacks, queue pressure, overload behavior and required platform limits.

**Existing test candidates:** tests/MetricsDiagnosticsTest.php, ProcessRunnerTest.php, ControlServerTest.php, DatagramAbuseAcceptanceTest.php, Http1AbuseAcceptanceTest.php, Http2AbuseTest.php, Http3AbuseFaultAcceptanceTest.php, BatchISoakFaultTest.php.

**Exit:** Incident-relevant bounded diagnostics and adversarial safety suite passing, with instrumentation overhead measured and recorded.

### R8 — standalone Runwire integration, complete QA and release certification

**7/10 R8 tasks complete**, including R8.02 full QA, R8.03 optional runtime matrix and standalone R8.08; real protocol/host promotion and complete Runwire-owned certification remain open. The historical external Infbyte publication-order failure is recorded but removed from Runwire's release gates by the 2026-10-20 scope decision; the existing CI workflow must still be updated accordingly. [R7/R8 audit](evidence/runwire-2.2/r7-r8-qa.md).

**Likely sources:** Runwire's .github/workflows/*.yml, benchmarks/*, tests/*, docs/deployment.md, docs/benchmarks.md; standalone Runwire-owned client/consumer fixtures only. No Foundation or Infbyte repo work.

**Tasks**
- [x] **R8.01** Audit required CI and fix missing/incorrect gates, always applying them to the candidate SHA. Reconcile normal PR workflows vs opt-in release certification, reporting skips honestly.
- [x] **R8.02** Run PHPForge full tests, static analysis, formatting/quality, security and release guard for PHP 8.4 + 8.5 with preferably both lowest and stable dependency modes where existing pipeline supports them. **Passed** [Security & Standards 39427241144 (attempt 2)](https://github.com/infocyph/Runwire/actions/runs/39427241144) PHP8.4/8.5 lowest/stable + analysis; [ext-event release guard 39427240745](https://github.com/infocyph/Runwire/actions/runs/39427240745); initial PHP8.4 lowest time out corrected by unchanged exact-SHA job retry.
- [x] **R8.03** Run accelerated ext-event, portable no-PCNTL, OpenSSL missing/fail-closed, Swoole/OpenSwoole, real QUIC/H3 and OPcache lanes. **Passed** [Portable Native 39427240811](https://github.com/infocyph/Runwire/actions/runs/39427240811), [Swoole/OpenSwoole 39427240740](https://github.com/infocyph/Runwire/actions/runs/39427240740), [HTTP3/QUIC 39427241144](https://github.com/infocyph/Runwire/actions/runs/39427241144), [Release Candidate 39427240721](https://github.com/infocyph/Runwire/actions/runs/39427240721), [Benchmarks 39427240739](https://github.com/infocyph/Runwire/actions/runs/39427240739).
- [x] **R8.04** Run real native H1, H2, H3, WebSocket, TCP/Unix, UDP and process lifecycle integration; explicitly note unavailable host infrastructure as blocking or accepted risk. **Verified:** [H1/H2/WS 39433339538](https://github.com/infocyph/Runwire/actions/runs/39433339538), [H3/QUIC 39433340291](https://github.com/infocyph/Runwire/actions/runs/39433340291), [native TCP/Unix/UDP 39433339585](https://github.com/infocyph/Runwire/actions/runs/39433339585), [live Swoole workers 39433339597](https://github.com/infocyph/Runwire/actions/runs/39433339597) on immutable `1770ba8`; external host gaps remain R6.02/R6.10 and short H2 load is NOT release-certified.
- [ ] **R8.05** Update and run the Runwire-owned release-certification workflow on the exact 2.2 candidate SHA; verify matched sustained performance, 30-minute native HTTP soak, standalone Runwire Composer/real-host consumer smoke, and PHPForge release guard. Remove the current mandatory Infbyte CI job; do not let unrelated Foundation/Infbyte dependency resolution determine Runwire release readiness.
- [x] **R8.06** Modify primary matched performance baseline to **immediate predecessor tag 2.1.2** with verified source SHA and output provenance; separately preserve historical dc78bdc9... comparisons if still useful. This is a deliberate workflow/benchmark change, not evidence already achieved.
- [ ] **R8.07** Ensure matched sustained runs execute five 180-second measured trials after warmup using comparable environments; enforce throughput regression budget, response correctness, confidence/variance and resource/tail bounds.
- [x] **R8.08** Run Runwire's isolated package consumer and owned end-to-end fixtures against the exact candidate, covering real HTTP/native-host behaviors without depending on, testing or modifying Foundation, Infbyte, or other external consumer repositories. Record installation, response correctness, lifecycle outcomes and missing-host limitations. **Verified:** [PHP 8.4/8.5 standalone native HTTP CI 39426401438](https://github.com/infocyph/Runwire/actions/runs/39426401438) on source `9940779`; archive installed from path repository without symlinks, source cmp, autoload/AUTO defaults, live 200/400 responses, process teardown; wider H2/H3/recycle/real host matrix remains R8.04/R6.
- [x] **R8.09** Audit test artifacts for false success, expected skips, timing flakiness, malformed JSON metrics, missing test jobs, altered benchmarks and stale pinned dependencies.
- [ ] **R8.10** Build a signed-off test matrix with per-mode and per-protocol evidence, known constraints and release-risk register. No performance headline unsupported by same-topology repeated evidence.

**Exit:** All Runwire-owned mandatory certification gates actually ran and passed for the exact candidate; standalone Runwire consumer and real-host acceptance recorded; zero unresolved Runwire-owned critical/high blockers. Third-party package publication issues are out of scope.

### R9 — developer-facing documentation, release-quality finalization

**Release-documentation audit complete (R9.01–R9.04):** [Draft 2.2 notes](../release-notes-2.2.md) compare the immutable published 2.1.2 tag, with no unqualified RPM claim; README/API/coroutine/host ownership and scheduling boundaries are current, and no public/protected API surface change was introduced. [Release handoff draft](evidence/runwire-2.2/release-handoff.md) records unresolved final gates. R9.05–R9.09 remain open until final candidate certification and maintainer handoff.

**Likely sources:** README.md, docs/getting-started.md, architecture.md, deployment.md, coroutines.md, benchmarks.md, migration-2.0.md (historical), SECURITY.md, composer.json, new docs/release-notes-2.2.md if chosen.

**Tasks**
- [x] **R9.01** Summarize final behavior vs 2.1.2 only (or actual immediately preceding tag if maintainers publish another), with change descriptions backed by commits and tests; do not report draft metrics as certified.
- [x] **R9.02** Update API examples and runtime selection, capability table, hosted ownership, coroutine blocking boundary, transport/TLS/QUIC requirements, graceful shutdown and production deployment.
- [x] **R9.03** Document opt-in scheduling tuning and measured tradeoffs; retain supported defaults and fail-closed behavior.
- [x] **R9.04** Document any intentional 2.2 API changes, exact compatibility/migration and deprecations; keep no-op migrations out of the changelog.
- [x] **R9.05** Verify clean Composer archive, excluded development artifacts, reproducible install, supported PHP versions/extensions and safe default configuration.
- [ ] **R9.06** Re-run final source audit, complete QA and certification when code/docs/workflow changes could affect results; all evidence must match the final release candidate.
- [ ] **R9.07** Check zero release-blocking QA findings, fuzz/correctness defects, security advisories, unexplained benchmark changes or cross-host integration failures.
- [ ] **R9.08** Prepare release handoff: candidate SHA, checklist, compare link, full changelog, benchmark methodology/results, complete test matrix, nonblocking limitations and maintainer manual release steps.
- [ ] **R9.09** Leave branch/PR unmerged and untagged until explicit maintainer instruction; only record **READY FOR MAINTAINER RELEASE** when exit criteria are met.

**Exit:** Traceable, complete 2.2 candidate with release handoff and trustworthy evidence; no automatic publication.

### Mandatory execution matrix and ownership map

| Axis | Modes that must be considered | Key risks |
|---|---|---|
| PHP | 8.4, 8.5; 64-bit | ABI/behavior and toolchain differences |
| Runtime | Native prefork, native portable, FPM, FrankenPHP classic/worker, RoadRunner, Swoole, OpenSwoole | Listener ownership, bootstrap lifetime, worker replacement |
| Event | ext-event native, SelectLoop fallback, Swoole reactor | wakeups, descriptor counts, shutdown |
| HTTP | H1 plaintext/TLS, H2/TLS, H3/QUIC | parser, flow-control, stream cancellation, ALPN |
| Socket | TCP, Unix-domain, UDP, WebSocket H1 upgrade | framing, origin policy, backpressure, timeouts |
| Lifecycle | boot/ready/handle/reset/drain/shutdown, reload/recycle/crash | stale context, accepted admission, resource leaks |
| Traffic | low load, saturation, mixed interactive/bulk, connection churn | fairness, p99, stable queues |
| Security | malformed/incomplete frames, oversized inputs, TLS failure, untrusted subprocess inputs | fail-closed, bounded memory, privilege boundary |
| Resources | PHP allocator, RSS/cgroup, FDs, queue counts, callback time | leaks, allocator vs RSS confusion |
| Dependencies | min/stable where required, no optional extension, actual optional extension | honest capabilities, portable behavior |

### Performance decision protocol

1. Primary KPI = **successful sustained RPM**, measured across repeatable full-server workloads and verified correct responses; note successful RPS as supplementary.
2. Collect warmup then repeated steady-state trials, preferably 5 for release-grade decisions. Include concurrency sweep, peak sustainable throughput and saturation knee.
3. Keep identity of baseline/candidate, CPU/OS, workers, transports, TLS/ALPN, PHP/ext versions, OPcache, payload, headers, keepalive, client and sample duration constant.
4. Compare median successful RPM, coefficient of variation, p50/p95/p99, errors/timeouts, CPU use, peak and plateau RSS, growing queues, reconnection and open sockets/FDs.
5. Existing matched release comparison has a **5% throughput regression budget**; do not quietly change it or confuse it with a minimum improvement target. Agree tail-latency/RSS budgets during R0 with real variance and operational constraints.
6. Higher throughput with uncontrolled queue growth, response failure, unsafe stale state, resource exhaustion or materially worsened p99 **does not pass**. A real trade-off requires explicit decision and documentation before promotion.
7. Instrumentation overhead is measured: disabled diagnostics should not change the critical execution path appreciably.
8. For an unproven optimization, capture negative result and stop; don't continue a speculative rewrite for aesthetic reasons.

### Batch progress log template

For every completed or attempted task, add a dated entry in an evidence manifest rather than deleting failed experiments:

| Field | Example format |
|---|---|
| Task | R3.02 |
| Status | NOT STARTED / IN PROGRESS / BLOCKED / DONE / REJECTED WITH EVIDENCE |
| Why | Baseline profiler and reproduction |
| Files | Source/test/docs/benchmark paths |
| Candidate SHA | Exact commit |
| Functional QA | Commands, failures, pass counts |
| Real-host/interop | Modes, client versions, failure simulations |
| Benchmark | Baseline + candidate raw artifact URLs; RPM, tail, CPU/RSS |
| Gate | Passed / failed / skipped with reason |
| Next action | Precise remaining work or next batch |

### Plan integrity rules

- The **canonical tracker** is the R0–R9 table plus R0.01–R9.09 checkboxes above. Both must agree; update batch status only after its task/evidence requirements are complete.
- Do not mark an entire batch done merely because all code is committed; QA + benchmarks + integration + evidence + tracker sync are part of completion.
- Do not rewrite the plan to erase failed trials; distinguish negative results from unfinished requirements.
- At any user request for progress, report Rn task completion fraction, blocking failures, exact head SHA and whether the corresponding mandatory CI jobs actually passed.
- If an optional workload is genuinely unavailable, classify as unverified and identify the dependency needed; avoid pretending it passed.
- Only Runwire repository edits are authorized; Foundation and Infbyte are fully independent and cannot be changed or required by certification. No required dependencies on the two Rust libraries, no merge/tag/publish actions under this planning authorization.


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

1. Which workloads are the business-critical performance targets? Select primary and secondary, rather than benchmark cherry-picking.
2. Which existing contracts may intentionally break in a major version? No automatic BC promise.
3. What measurable regression budgets apply to p99 and RSS, alongside existing 5% sustained-throughput budget?
4. What is the minimum supported native QUIC build/ABI for the release matrix?

**This branch contains actual runtime, test, workflow and documentation commits. The canonical tracker and summary table reflect completed evidence; outstanding tasks remain unchecked and no merge/tag/publish is authorized.**

### R1 functional QA → R2 timer fairness implementation (2026-10-20)

**R1 evidence:** The preceding code commit `e4573a4e7924ed62c14fae51f7f3728d9d1d90ab` completed PHPForge Pest and analysis on PHP8.4/8.5 stable/lowest dependencies, Swoole/OpenSwoole and portable-native lanes; long unrelated QUIC/diagnostics jobs were still running at the time of this transition. Task R1.02 is the real code simplification of CGI HTTP header normalization, matching existing scalars and validation. R1.03 is a **documented no-change optimization decision**: RequestFinalizer already provides exactly-once response terminal/cleanup and exception aggregation, so riskier finalizer rewrites were rejected. R1.04 exception aggregation and R1.05 identity/single-activation contracts are verified in `ApplicationLifecycleTest`, `RequestContextActivationTest`, `PersistentRuntimeIsolationTest`, and the new `R1RequestLifecycleRegressionTest`. R1.06 cold/warmed hosted costs and OPcache footprint are measured in current R0 host profiling and existing PHPBench evidence. **R1.08 remains open**: final native/host comparable A/B with tracing cannot be certified from per-operation or cross-runner data.

**R2.02 implementation:** `SelectLoop::runDueTimers` previously drained all currently due callbacks in one tick, potentially preventing socket watchers from receiving an event-loop turn. A conservative 256-due-entry limit per tick ensures the reactor gets a chance to poll I/O during a 600-timer burst. A cancelled/stale timer still counts toward the per-tick work budget. Outstanding due timers remain in the heap and are delivered on later ticks. New `SelectLoopTest` cases assert exact work counts 256/512/600 and ready-socket progress at 256. Ext-event backend unchanged. This is a **fairness/correctness fix**, not an assumed successful RPM gain; focused/full CI must pass before R2.02 is considered QA certified. 

### R2 scheduling/cancellation audit QA and R3 byte-queue experiment (2026-10-20)

- R2.03: existing `MonotonicTimeTest`, `SelectLoopTest`, and `BatchLCoroutineSchedulerTest` validate ordering, monotonic deadlines, cancellation-before/after readiness and timer cancellation. R2.04: existing bounded ready queue, `TimerQueue` heap compaction and cancellation cleanup reviewed; no extra caching introduced.
- R2.05: `BatchMStructuredConcurrencyTest` verifies fail-fast/collect-all, nested deadline, task-local inherited snapshot and request-scope reuse. R2.06: `BatchNSynchronizationTest` verifies zero-capacity rendezvous FIFO, bounded waiters, semaphore ownership, mutex waiters, barrier cancellation/reuse. R2.07: [native portable 39372948300](https://github.com/infocyph/Runwire/actions/runs/39372948300) passed PHP8.4/8.5 and scalable ext-event; `EventLoopTest` covers >1024 descriptors, core SelectLoop remains 256 owned-connection limit. R2.08: `AsyncConnectionTest` and `BatchLCoroutineSchedulerTest` exercise I/O watcher deregistration, EOF, cancellation, pressure and close. Those source suites passed at prior green baseline; new bounded timer tests passed Pest on SHA `429d956e`. Pint initially failed constant ordering and was fixed on `5e14b750`; its full new CI still requires final confirmation. **R2.01** scheduler all-load allocation profiler and **R2.09** sustained mixed p99/queue soak remain open (R8 qualification).
- R3.02 experiment: `ByteQueue::read` had an `O(chunks)` loop that called `discard()` for *each* chunk, reapplying compaction checks and budget releases. The candidate performs exactly one aggregate budget release and compaction after reading, retaining chunk order, partial offsets, byte accounting and validation. Local PHP8.4.24 isolated emulation consumed 525k bytes over 350 repetitions: old 42.5ms vs candidate 31.3ms (same machine); this is **not** full-server RPS evidence. New `ByteQueueReadTest` covers partial reads, 300-chunk compaction, exact shared quota, interleaved discard and queue reuse. R3.02 **requires fresh CI** before being considered complete.

### Runwire-only scope reconciliation — 2026-10-20

Maintainer instruction: **do not touch Foundation or Infbyte**. This supersedes the roadmap's earlier language making the Infbyte consumer suite and Foundation/Webrick bridge release blockers. Such external consumer work is removed from R6.10/R8.05/R8.08 acceptance without treating any unfinished checkboxes as done. That old Infbyte job has now been replaced **within Runwire only** with a clean Composer package install and native HTTP/1.1 200/400 request smoke for PHP 8.4/8.5; fresh exact-SHA certification is still pending.

At source `5b88cff2f40824526ffcd91b56e5a310a60d692f`, the six standard CI workflows succeeded; the release-certification run [39379880727](https://github.com/infocyph/Runwire/actions/runs/39379880727) completed with a **passing 30-minute native soak and PHPForge release guard**, a **failed matched-performance acceptance due to excessive variation**, and a **failed external Infbyte Composer dependency resolution**. The last result is retained as truthful historical evidence, not an ongoing Runwire-owned release blocker. The next candidate must pass a refactored Runwire-only certification workflow.

**Canonical progress unchanged by this plan decision:** 64/95 checked, seven R5 dialer tasks deferred, **64/88 in scope (72.7%)** complete, **24 active open**. Do not infer code or QA completion from a documentation-only scope update.

### R8 certification isolation checkpoint — 2026-10-20

- **Runwire-only implementation:** [workflow `fd78bf9`](https://github.com/infocyph/Runwire/commit/fd78bf9481e45dae0dfc0712760b2f6fa2724ae4) replaced the external Infbyte checkout, Composer resolution, test suite and Foundation/Webrick bridge with an independently packaged Runwire consumer. Both supported PHP lanes install a ZIP through Composer's path repository (no symlink), verify source identity and AUTO defaults, and drive real native HTTP/1.1 success (200/`ok`) and invalid-path (400/`bad`) responses against the installed runtime. A JSON artifact records candidate SHA and validation. No outside repositories changed.
- **Benchmark verdict correction:** [comparator `09817e8`](https://github.com/infocyph/Runwire/commit/09817e878fd6003ed1f8c288a040396a191fe23c) now sets `passed=false` and `certification_status=inconclusive_variance` if either paired RPS CV breaches the strict 2.5% bound. [Tests `87cfca5`](https://github.com/infocyph/Runwire/commit/87cfca5a40e9bf9a9792a1d975a408cadc1e582c) exercise stable acceptance, high-variance rejection, and stable throughput-regression rejection. No threshold is lowered.
- **QA:** [new PR source-check run](https://github.com/infocyph/Runwire/actions/runs/39425802729) passed, as did [PHP 8.4/8.5 archive-install jobs](https://github.com/infocyph/Runwire/actions/runs/39425802479) at source `87cfca5`. Remaining [standard CI](https://github.com/infocyph/Runwire/pull/9/checks) and [release certification run 39425802472](https://github.com/infocyph/Runwire/actions/runs/39425802472) were still in progress or queued at this checkpoint; record their final conclusions before closing any R8 acceptance tasks. The documentation-only follow-up commit will require an exact-final-SHA QA reconciliation.
- **At this earlier pre-verification checkpoint** the ledger was 64/95 checked and 24 in scope open. Since then both standalone CI lanes passed and **R8.08 was individually closed** in the 2026-10-20 batch QA checkpoint below. R8.05 remains **NOT complete**; release blocked until full self-contained certification actually passes.

### Batch R8.08 — verified standalone consumer closure (2026-10-20)

- **Runwire source and ownership:** No edits or required suite in Foundation or Infbyte. Exact tested Runwire SHA: [`9940779`](https://github.com/infocyph/Runwire/commit/99407799fdee964bc50c64ef918d8f30d05ef432).
- **Certification evidence:** [Release Certification run `39426401438`](https://github.com/infocyph/Runwire/actions/runs/39426401438), both independently executed `Standalone Runwire package / native HTTP` jobs on PHP 8.4 and PHP 8.5 **SUCCEEDED**. Each built a clean ZIP, installed via a standalone Composer path consumer (copied package), checked archive/source identity and safe AUTO defaults, launched the installed native Runwire HTTP fixture, validated GET `/benchmark` → HTTP 200 `ok` and `/invalid` → HTTP 400 `bad`, and executed process termination/cleanup. Artifact `result.json` contains source SHA and correctness. This proves a bounded standalone native H1 acceptance, **not** all protocol or worker-generation lifecycle modes.
- **Focused QA red → fixed:** On source `87cfca5`, [PHPForge QA run `39425802995`](https://github.com/infocyph/Runwire/actions/runs/39425802995) exposed an outdated comparator test assuming noisy evidence could exit successfully, and two PHPCS warnings in a new fixture. [`9940779`](https://github.com/infocyph/Runwire/commit/99407799fdee964bc50c64ef918d8f30d05ef432) corrected both. [PHPForge Security/Standards run `39426401968`](https://github.com/infocyph/Runwire/actions/runs/39426401968) then passed Pest/QA for PHP 8.4/8.5 in both stable and lowest dependency modes and passed both static-analysis jobs; HTTP3/QUIC 8.5 remained in progress at this checkpoint.
- **Canonical accounting after R8.08:** 65/95 checked, seven R5 tasks deferred, **65/88 in scope (73.9%)** checked, **23 active open**. R8 is now 4/10; R8.05 still requires full release certification, notably stable paired throughput CV below 2.5%, soak and PHPForge guard at the final SHA. R6/R4 other host/protocol tasks are not implied by this consumer smoke.

### R8.02 / R8.03 — QA and optional-runtime matrix acceptance (2026-10-20)

- Exact tested Runwire SHA: [2e761de](https://github.com/infocyph/Runwire/commit/2e761ded1676b18a83417215659ebb9af55e99d2). [Security & Standards run 39427241144](https://github.com/infocyph/Runwire/actions/runs/39427241144) finished **success on attempt 2**: Pest, PHPCS/Pint, analysis and PHPForge quality lanes on PHP8.4/8.5, stable and lowest Composer modes. PHP8.4 lowest originally exceeded the 300s task timeout, then passed unchanged on exact-SHA job retry. This timing flakiness remains recorded; do not claim the first attempt passed or weaken QA time limits. The conditional PHPForge Security Report job was skipped, not counted as passing.
- [Release Certification 39427240745](https://github.com/infocyph/Runwire/actions/runs/39427240745) verified the **PHPForge release guard with ext-event succeeded**; standalone package/HTTP smoke passed PHP8.4/8.5. The 5×180s performance and 30m soak were still active at this checkpoint, not accepted here.
- [Portable Native 39427240811](https://github.com/infocyph/Runwire/actions/runs/39427240811) passed PHP8.4/8.5 genuinely no-PCNTL/POSIX, TLS and disabled OpenSSL crypto-function fail-closed, and ext-event accelerated lane. [Swoole/OpenSwoole 39427240740](https://github.com/infocyph/Runwire/actions/runs/39427240740) passed four PHP8.4/8.5 extension modes including live two-worker identity. HTTP3/QUIC PHP8.4/8.5 jobs of Security & Standards passed OpenSSL/extension ABI and independent aioquic and PHP8.5 ngtcp2 interop. [Release Candidate 39427240721](https://github.com/infocyph/Runwire/actions/runs/39427240721), [Source Audit 39427240706](https://github.com/infocyph/Runwire/actions/runs/39427240706), and [Benchmarks 39427240739](https://github.com/infocyph/Runwire/actions/runs/39427240739) all succeeded; OPcache-enabled benchmark/runtime checks retained.
- R8.02 and R8.03 closed as executed and evidenced; R8.04 real comprehensive protocol/fault integration, R8.05 full release certification, R8.07 statistically stable paired performance, and R9.06/R9.07 final frozen-source QA remain separate gates. Rerun relevant QA after any new production source change.


### R6.07 — retired worker generation isolation accepted (2026-10-20)

- [Production fix b820293](https://github.com/infocyph/Runwire/commit/b820293e67da2e6b1473088f0d99775e96ea658e): close() marks the retiring WorkerContext as stopping before disposing of scope, periodic timer and reactor handles, so it cannot advertise background admission after closure. The transition is idempotent.
- [WorkerGenerationIsolationTest from b820293 and d9117e7](https://github.com/infocyph/Runwire/commit/d9117e732e2f8a17602fed17273528330eb15d22): three focused Pest cases cover idempotent closure, rejected late background and periodic admission, cancelled suspended coroutine tasks, old periodic handle invalidation while a new generation reuses the same task name, no old callback bleed, timer/watch cleanup, retired application rejection, completed request-context rejection and successful fresh-generation application handling. Existing BatchPSoakTest and NativeWorkerLifecycleTest continue to assert multi-generation drains and request ownership.
- [Security & Standards run 39429653975](https://github.com/infocyph/Runwire/actions/runs/39429653975) at exact code source d9117e7 passed all four PHPForge Pest QA combinations (8.4/8.5 × lowest/stable), PHP8.4/8.5 analysis, PHPCS and Pint. [Portable Native 39429653366](https://github.com/infocyph/Runwire/actions/runs/39429653366), [Swoole/OpenSwoole 39429653245](https://github.com/infocyph/Runwire/actions/runs/39429653245), [Source Audit 39429653353](https://github.com/infocyph/Runwire/actions/runs/39429653353), and [Release Candidate 39429653220](https://github.com/infocyph/Runwire/actions/runs/39429653220) passed. H3 PHP8.5 and full release certification were still running at this evidence checkpoint, so those are not credited.
- **R6.07 complete for local generation invalidation**. R6.02 remains the live multi-protocol process restart/recycle stress and R6.10 remains the complete real-host environment matrix; neither is implied by these in-process tests. No Foundation or Infbyte work performed.


### Release-certification trigger isolation — 2026-10-20

- **Root cause of repeated stalled certification:** the old Runwire-only Release Certification workflow fired on every pull_request synchronize while the PR already carried a release-certification label, and its PR-wide concurrency group had cancel-in-progress=true. Even documentation/tracker updates repeatedly cancelled the costly 30-minute soak and 5 ×180s comparison before any verdict existed.
- **Fixed in this Runwire branch:** optional full certification now starts only on an explicit GitHub Actions workflow_dispatch for the selected candidate ref, or when maintainers explicitly apply the release-certification label to the PR. The four release jobs remain required and unchanged (matched performance, 30-minute soak, PHP 8.4/8.5 Runwire-only packaged HTTP smoke, PHPForge release guard). Concurrency no longer automatically cancels a prior evidence run; avoid unnecessary duplicate manual dispatches.
- **Batch workflow:** ordinary PR commits still run all six standard CI workflows, including critical PHPForge/QUIC/protocol checks. Perform R0–R7 engineering, focused QA and canonical tracker commits in batches. Once source and release-documents are frozen, trigger the full certification on that exact candidate SHA and validate all artifacts before R8.05/R8.07 or R9 final signoff. If new code is committed after certification, repeat certification; no stale-SHA pass is acceptable. R8.05 stays unchecked at this implementation checkpoint pending actual final-run proof.

### Verified R8.04 — Runwire-only integration matrix (2026-10-20)

All six standard workflows were green on immutable source [1770ba8](https://github.com/infocyph/Runwire/commit/1770ba82cd8be96816d673237cc0e2cac8ccb0ac). [Benchmarks run 39433339538](https://github.com/infocyph/Runwire/actions/runs/39433339538) exercised native HTTP/1.1, independent Python h2 real TLS multiplexed requests, and native WebSocket upgrades/framing. [Security & Standards 39433340291](https://github.com/infocyph/Runwire/actions/runs/39433340291) passed real QUIC/OpenSSL HTTP3 native server interoperability with independent aioquic on PHP8.4/8.5 and ngtcp2 on PHP8.5. [Portable Native 39433339585](https://github.com/infocyph/Runwire/actions/runs/39433339585) passed PHP8.4/8.5 real HTTP/TCP/Unix/UDP/TLS local-response tests in truly no-PCNTL/POSIX mode. [Swoole/OpenSwoole 39433339597](https://github.com/infocyph/Runwire/actions/runs/39433339597) passed live multiworker lifecycle and identity checks with both extension families.

**R8.04 passed**, as bounded actual network/transport integration—not as release performance certification. H2 comparison JSON is explicitly `diagnostic=true`, `promotion_certified=false`: 1s warmup/5s phases and noisy throughput do not meet the strict 30s warmup, 5×180s, 2.5% CV release threshold. Real FrankenPHP/RoadRunner are not available in current CI and remain **open R6.10**; mixed multi-protocol native prefork rolling replacement remains **open R6.02**. R4.01/.02/.04/.09 and R8.05/.07 stay open. The newer 30-minute soak RSS/FD sampler is implemented but not yet run and accepted; R7.08 remains open, including unavailable external GC/OPcache attribution.
