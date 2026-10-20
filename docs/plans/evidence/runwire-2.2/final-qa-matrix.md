# Runwire 2.2 — final QA matrix and remaining release blockers

**State:** NOT READY — immutable source qualification not yet complete; no merge, tag, or publication.
**Repository:** infocyph/Runwire only — [PR #9](https://github.com/infocyph/Runwire/pull/9), branch plan/runwire-independent-ecosystem-roadmaps.
**Baseline:** published 2.1.2 at 7e9bf20ad0737a187dfed77b723791b141b36378.
**Tracker:** 71/95 checked, seven R5.02–R5.08 intentionally deferred beyond 2.2; **71/88 in-scope passed (80.7%), 17 active remaining**. See [canonical task tracker](../../runwire-2.2-runtime-improvement-plan.md).
**Latest fully-green six-standard-workflow PR head before current sampler change:** 7025e4e8d207b299a96eb0e10b5c0dbd2a9c18ac. Current corrected sampler source 7609bed requires a fresh final-source QA. Later resource sampler PHPForge fix: d2646e2919317cc376f19fd1237affb1dbc87679. The final docs commit is not a release acceptance stamp.

## Accepted integration / development QA evidence

| Area | Evidence | Result / limit |
|---|---|---|
| PHP8.4/8.5 PHPForge QA, lowest/stable, analysis/security | [Standard QA 39433340291](https://github.com/infocyph/Runwire/actions/runs/39433340291) | PASS at previous code; final-source rerun still required |
| Source audit, package, API/source identity | [Audit 39433339545](https://github.com/infocyph/Runwire/actions/runs/39433339545), [Package 39433339556](https://github.com/infocyph/Runwire/actions/runs/39433339556) | PASS at previous code |
| Native portable PHP8.4/8.5, TCP/Unix/UDP/TLS and missing process extension | [Portable 39433339585](https://github.com/infocyph/Runwire/actions/runs/39433339585) | PASS, no real external FrankenPHP/RoadRunner here |
| Actual Swoole/OpenSwoole two-worker lifecycle | [Swoole 39433339597](https://github.com/infocyph/Runwire/actions/runs/39433339597) | PASS PHP8.4/8.5 |
| H1/H2 native real requests + WebSocket independent protocol clients | [Benchmarks 39433339538](https://github.com/infocyph/Runwire/actions/runs/39433339538) | PASS transport correctness; H2 short-run performance diagnostic only |
| H3 native php-quic and aioquic/ngtcp2 clients | [QUIC jobs 39433340291](https://github.com/infocyph/Runwire/actions/runs/39433340291) | PASS; real supported OpenSSL ABI |
| Isolated copied Composer ZIP consumer native H1 200/400 | [Release certification 39426401438](https://github.com/infocyph/Runwire/actions/runs/39426401438) | PASS PHP8.4/8.5 at prior SHA |
| Comparative baseline 2.1.2 vs candidate | [Prior full certification 39379880727](https://github.com/infocyph/Runwire/actions/runs/39379880727) | NOT CERTIFIED: matched throughput CV exceeded 2.5% |
| 30-minute successful H1 soak | [Prior 39379880727](https://github.com/infocyph/Runwire/actions/runs/39379880727) | PASS prior response correctness; insufficient process RSS/FD/GC trend proof |
| New 15s RSS/FD Linux soak sampler | [Source 9047771](https://github.com/infocyph/Runwire/commit/90477715f93c32feb2c3db188df0983edae61ac1), [fix d2646e2](https://github.com/infocyph/Runwire/commit/d2646e2919317cc376f19fd1237affb1dbc87679) | Implemented, new exact-SHA QA + 30m acceptance pending; GC/OPcache process internals not directly sampled |

Standard PR jobs may check out a synthetic merge commit; the benchmark artifact runtime_build stamp can therefore differ from the PR branch head. Do **not** interchange ordinary CI completion, workload microbenchmarks and exact-source release certification. The opt-in workflow explicitly checks out candidate head SHA; its run ID, source identity, full artifacts and conclusions must all be recorded.

## Remaining 17 in-scope canonical task IDs

| Batch | IDs | Evidence still required |
|---|---|---|
| R0 | R0.06, R0.07 | End-to-end cold/warm attribution and warmed concurrency/RPM/latency/CPU/RSS/FD curves |
| R1 | R1.08 | Native/host trace-on vs trace-off matched request throughput |
| R2 | — | R2.09 accepted with 3-tier PHP8.4/8.5 real socket/CPU/timer p99 and cleanup evidence |
| R3 | R3.09 | H1/TLS streaming write bytes/syscalls and size/keepalive performance matrix |
| R4 | R4.01, R4.02, R4.04, R4.09 | Qualified multiplex H2/H3, HPACK/QPACK hotspots and QUIC poll/write, slow-peer/cancel-storm stress |
| R6 | R6.02, R6.10 | Live native prefork crash/reload/mixed transport plus actual FrankenPHP/RoadRunner in isolated Runwire host fixtures |
| R7 | R7.08 | Full 30-minute process RSS/FD trajectory, GC/OPcache/queue retention and leak/cache separation |
| R8 | R8.05, R8.07, R8.10 | Single frozen head 5×180s matched CV <2.5%, full sampled 30m soak and signed-off matrix |
| R9 | R9.06, R9.07, R9.09 | Final exact-head QA/security/risk reconciliation; release handoff prepared as R9.08, no automatic release |

## Non-blocking scope / authorization

Runwire-Core and Runwire-Cells are independent Rust libraries and not runtime dependencies. R5 new nonblocking dialer API is consciously deferred after 2.2. **Never touch Foundation or Infbyte**; historical unpublished dependency issues in an external consumer are not Runwire release blockers and not a valid reason to weaken Runwire's own QA. Real third-party host binaries, if unavailable, must stay visible as a Runwire test-infrastructure gap. Maintainer alone controls merge, tag and publication.

## Release sequence

Complete R0–R7 missing work and real host/client tests; qualify throughput with same-host warmup and 5×180s CV below 2.5%; prove the 30-minute sampled resource/GC/OPcache soak; freeze source and docs; explicitly trigger release-certification on that SHA; record all five release jobs (matched, soak, PHP8.4 consumer, PHP8.5 consumer, release guard), six standard QA conclusions and security advisory snapshot; close remaining R8/R9 checkboxes only on verified evidence. Until then **NOT READY**.


### R2.09 sustained mixed-scheduler pressure QA — accepted (2026-10-20)

**Exact source:** [93416ea](https://github.com/infocyph/Runwire/commit/93416eaabd367146e565d3e0752ed7cf1f992a37).
[PHP 8.4 and PHP 8.5 benchmark jobs 39441738432](https://github.com/infocyph/Runwire/actions/runs/39441738432) both passed the same candidate source for three 3-second load tiers with real Unix socket pair I/O, 4ms repeat timers, and CPU burst levels of 8, 32 and 96 callbacks. Each tier wrote 750 and read 749 validated bytes (one last write pending at controlled stop), held at most one queued byte, and cleaned all remaining timers/readable watchers/deferred callbacks. Peak *sampled PHP allocation drift* was zero in these bounded runs; this does not prove no long-run native process leak.

| PHP | CPU callbacks per tick | p99 readable progress | Max pending bytes | Leaked timers/watchers |
|---|---|---|---|---|
| 8.4.26 | 8 / 32 / 96 | 0.081 / 0.140 / 0.393 ms | 1 | 0 |
| 8.5.11 | 8 / 32 / 96 | 0.075 / 0.178 / 0.378 ms | 1 | 0 |

The first [benchmark run 39441425725](https://github.com/infocyph/Runwire/actions/runs/39441425725) failed because the harness itself left the *last* burst's one-shot CPU timer registrations pending when its timeout fired; [fix 93416ea](https://github.com/infocyph/Runwire/commit/93416eaabd367146e565d3e0752ed7cf1f992a37) explicitly cancels those handles and emits per-field accounting on failure. This remains a fail-closed test, not a waived assertion. [Security & Standards run 39441739912](https://github.com/infocyph/Runwire/actions/runs/39441739912) passed all four PHPForge Pest/format QA variants (8.4/8.5 stable/lowest) at the exact source; optional QUIC/analysis jobs were not all finished when this task was closed.

**R2.09 completed** as a scheduler-local sustained fairness/p99/queue proof; comparison versus published 2.1.2, traffic at production protocol/network saturation and long-run process RSS drift remain independent R0/R3/R4/R7/R8 gates. Do not infer a successful-RPM speedup from this short 3×3 second micro/mixed benchmark.

## Prepared maintainer handoff

[R9.08 release-handoff.md](release-handoff.md) contains source checkpoint and final SHA validation instructions, baseline/changelog/diff links, exact 5×180-second performance + sampled 30-minute soak methodology, known real-host limitations, QA evidence/run IDs and non-authorizing release instructions. R9.08 closes a writing/preparation task only; no remaining Runwire release gate is waived.


### R7/R8 final-certification evidence and process-tree sampler correction — 2026-10-20

- [Opt-in Release Certification 39438778354](https://github.com/infocyph/Runwire/actions/runs/39438778354) completed on **exact Runwire candidate `d69ddfa7b2af49a03c3b648c81348ef54ef005d7`**. **Four of five jobs passed**: [30-minute HTTP/1.1 soak artifact 12274868566](https://github.com/infocyph/Runwire/actions/runs/39438778354/artifacts/12274868566), clean PHP 8.4+8.5 Composer/native HTTP smoke, and PHPForge ext-event release guard. **Matched-performance job failed the strict CV gate.** This is a failed release-certification run, not an accepted speedup.
- **Five 180-second paired A/B trials** against published 2.1.2 at `7e9bf20`, same AMD EPYC 9V45 runner, PHP 8.5.11, one worker/16 keep-alive clients, 30-second runtime warmups, alternating order. [Artifact 12273863432](https://github.com/infocyph/Runwire/actions/runs/39438778354/artifacts/12273863432): baseline median **19,515.247 successful RPS**, candidate **20,216.440** (**+3.593% observed**, not certified); baseline RPS CV **2.984%** versus candidate CV **1.325%**, so max **2.984% exceeds strict <2.5%**. p99 1.86→1.85ms, peak process-tree RSS 129,458,176→122,540,032 bytes; no correctness, p99, RSS or -5% regression-budget failure. Comparator correctly reports `budget_enforced=false`, `passed=false`, `certification_status=inconclusive_variance`.
- **30-minute soak passed**: **11,141,982 / 11,141,982** requests complete and successful, zero errors/timeouts/validation failures, one worker/32 keep-alive clients, p99 10.97ms, benchmark process-tree peak RSS **121,372,672 bytes**. Prior [RSS/FD resource sampler artifact](https://github.com/infocyph/Runwire/actions/runs/39438778354/artifacts/12274868566) had 122 observations across 1815 seconds, root PID RSS early/late median 78,938,112 bytes and root FD median 12, no observed growth. **Critical limitation:** original sampler covered only the supervisor/root PID while the HTTP benchmark reported process-tree peak; root-only stability does not prove child worker RSS/FD stability.
- **Runwire-only correction:** [code `8828851`](https://github.com/infocyph/Runwire/commit/8828851a65d6d77924f0311780e2ee439f3c706e) makes the external sampler recursively collect the supervisor and descendants' process RSS/virtual memory, FD count, and number of live worker processes, with explicit drift/coverage guards. Summed VmRSS is **not PSS** and can double-count shared mappings. [PHPForge no-skip fix `7609bed`](https://github.com/infocyph/Runwire/commit/7609bed1146cf6147506afb0ddde3a1ec2d808c2) replaces an unsupported test skip; **full six-workflow QA and a fresh 30-minute process-tree soak on the corrected head are still pending** at this checkpoint. PHP GC/OPcache *inside worker processes* and request-reference retention remain unmeasured by this external sampler.
- **Canonical status remains 71/95 checked, 7 R5 deferred, 71/88 in scope complete (80.7%), 17 active open.** No R7.08/R8.05/R8.07 final-qualification checkbox is checked merely because an earlier run contained individual passing jobs. The opt-in certification must be rerun on one **frozen corrected Runwire head**, with CV <2.5% on both paired populations and all gates passed. Foundation and Infbyte are entirely independent, untouched, and not in the release verdict. No merge/tag/publication.
