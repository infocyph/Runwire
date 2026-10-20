# Runwire 2.2 — critical-path closure register

**Status:** Active engineering, not release-ready. Updated 2026-10-20.
**Verified head:** `f804a5a6f6e7d9844dd18faedc15cba001bbc80f` (PR [#9](https://github.com/infocyph/Runwire/pull/9)).
**Release predecessor:** `2.1.2` at `7e9bf20ad0737a187dfed77b723791b141b36378`.

## Exact accounting

Original 95 task IDs: 59 checked complete; **29 active open**; 7 R5 dialer tasks (R5.02–R5.08) deliberately deferred outside 2.2 and left unchecked. The 2.2 in-scope total is 88: 59 complete + 29 open. These counts are computed from the canonical plan tracker, not estimates. No PR/QA badge alone certifies release readiness.

## Unchecked active tasks, grouped by batch

| Batch | Remaining task IDs | Primary gate |
|---|---|---|
| R0 (2) | R0.06, R0.07 | Actual request-path attribution and stable same-host concurrency A/B |
| R1 (1) | R1.08 | Native/host trace on/off matched performance |
| R2 (2) | R2.01, R2.09 | Task allocation/pressure cost curves and mixed scheduler soak |
| R3 (1) | R3.09 | HTTP1/streaming small/bulk matched performance and syscalls |
| R4 (5) | R4.01, R4.02, R4.04, R4.06, R4.09 | H2/H3 realistic saturation, HPACK/QPACK hotspots, independent QUIC clients and cancellation storm |
| R5 (0 in scope) | R5.02–R5.08 deferred | Deliberately not an R2.2 dialer release |
| R6 (4) | R6.02, R6.07, R6.08, R6.10 | Restart/recycle, handle generation, privilege transition and real hosts |
| R7 (2) | R7.08, R7.09 | Final RSS/queue/GC soak and advisory audit |
| R8 (7) | R8.02, R8.03, R8.04, R8.05, R8.07, R8.08, R8.10 | Full exact-SHA certification, independent clients/consumer and risk signoff |
| R9 (5) | R9.05, R9.06, R9.07, R9.08, R9.09 | Freeze, verify, handoff; maintainer controls release |

## Active ownership / decision boundaries

**Runwire-owned engineering work:** R2.01, R4.02/.04, R6.02/.07/.08, R7.09 can receive focused tests/profiling and individual batch QA without waiting for unrelated published Foundation packages. Any source optimization still needs its own end-to-end same-host proof.

**Shared performance bench:** R0.06/.07, R1.08, R2.09, R3.09, R4.01/.09 and R7.08 should reuse a single pinned runner and production-equivalent workload matrix. The 2.1.2 comparison produced zero-invalid-response paired trials but baseline coefficient of variation 4.283%, above the strict 2.5% threshold. Do not mark these tasks complete until new stable reproducible data exists.

**External dependencies:** R4.06 ngtcp2/aioquic interoperability requires exact protocol/client ABI, R6.10 real supported hosts, R8.05 Infbyte consumer. Current Infbyte main requires unpublished Foundation `^3.0.1`; Foundation 4 and Infbyte 4 candidates are draft and must not be falsified as published or modified under this PR.

**Release-only tasks:** R8/R9 are *not* prerequisites to develop safe R1–R7 code; they are mandatory before merging/tagging/publishing. The full 30-minute soak need not rerun for a documentation-only commit, but must pass on final exact source SHA.

## Latest proven standard QA

All six [source head `f804a5a6`](https://github.com/infocyph/Runwire/commit/f804a5a6f6e7d9844dd18faedc15cba001bbc80f) workflows succeeded:
- [Security & Standards](https://github.com/infocyph/Runwire/actions/runs/39374716440)
- [Benchmarks](https://github.com/infocyph/Runwire/actions/runs/39374716128)
- [Portable Native](https://github.com/infocyph/Runwire/actions/runs/39374715960)
- [Swoole/OpenSwoole](https://github.com/infocyph/Runwire/actions/runs/39374715907)
- [Release Candidate](https://github.com/infocyph/Runwire/actions/runs/39374716170)
- [Source Audit](https://github.com/infocyph/Runwire/actions/runs/39374716040)

[Release Certification](https://github.com/infocyph/Runwire/actions/runs/39374715989) was conditionally **SKIPPED** and is **NOT PASSING CERTIFICATION EVIDENCE**.

## Immediate engineering sequence

1. Record real profiler and task-cost evidence for R2.01 alongside the R0.06 native end-to-end hotspot model; keep task unchecked until the required test matrix and measured peak ready backlog are actually available.
2. Perform R4.02/R4.04 protocol codec profiling and independent client fault tests; do not rewrite HPACK/QPACK merely from isolated microbenchmarks.
3. Validate R6 real-worker restart and privilege transitions and R7 advisory provenance with focused QA.
4. Consolidate identical-host sustained performance and interop data before freezing the final R8/R9 candidate.

**Non-goals:** no partial release, no weakened CV/p99/RSS correctness gates, no fake dependent Composer versions, no Rust interdependency and no automatic merge or tag.
