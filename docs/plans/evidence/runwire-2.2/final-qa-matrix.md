# Runwire 2.2 — final QA matrix and remaining release blockers

**State:** NOT READY — immutable source qualification not yet complete; no merge, tag, or publication.
**Repository:** infocyph/Runwire only — [PR #9](https://github.com/infocyph/Runwire/pull/9), branch plan/runwire-independent-ecosystem-roadmaps.
**Baseline:** published 2.1.2 at 7e9bf20ad0737a187dfed77b723791b141b36378.
**Tracker:** 70/95 checked, seven R5.02–R5.08 intentionally deferred beyond 2.2; **70/88 in-scope passed (79.5%), 18 active remaining**. See [canonical task tracker](../../runwire-2.2-runtime-improvement-plan.md).
**Latest fully-green standard PR workflow head:** 1770ba82cd8be96816d673237cc0e2cac8ccb0ac. Later resource sampler PHPForge fix: d2646e2919317cc376f19fd1237affb1dbc87679. The final docs commit is not a release acceptance stamp.

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

## Remaining 18 in-scope canonical task IDs

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
| R9 | R9.06, R9.07, R9.08, R9.09 | Final exact-head QA/security/risk reconciliation and maintainer handoff, no automatic release |

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
