# Runwire 2.2 — critical-path closure register

**Status:** Active engineering, not release-ready. Updated 2026-10-20.
**Last pre-isolation fully green standard QA source:** `5b88cff2f40824526ffcd91b56e5a310a60d692f`. **Certification-isolation implementation checkpoint:** `87cfca5a40e9bf9a9792a1d975a408cadc1e582c` (PR [#9](https://github.com/infocyph/Runwire/pull/9)); final all-job QA still pending.
**Release predecessor:** `2.1.2` at `7e9bf20ad0737a187dfed77b723791b141b36378`.

## Exact accounting

Original 95 task IDs: 65 checked complete; **23 active open**; 7 R5 dialer tasks (R5.02–R5.08) deliberately deferred outside 2.2 and left unchecked. The 2.2 in-scope total is 88: 65 complete + 23 open. These counts are computed from the canonical plan tracker, not estimates. No PR/QA badge alone certifies release readiness.

## Unchecked active tasks, grouped by batch

| Batch | Remaining task IDs | Primary gate |
|---|---|---|
| R0 (2) | R0.06, R0.07 | Actual request-path attribution and stable same-host concurrency A/B |
| R1 (1) | R1.08 | Native/host trace on/off matched performance |
| R2 (1) | R2.09 | Sustained mixed timer/network/CPU soak under pressure |
| R3 (1) | R3.09 | HTTP1/streaming small/bulk matched performance and syscalls |
| R4 (4) | R4.01, R4.02, R4.04, R4.09 | H2/H3 realistic saturation, HPACK/QPACK hotspots, independent QUIC clients and cancellation storm |
| R5 (0 in scope) | R5.02–R5.08 deferred | Deliberately not an R2.2 dialer release |
| R6 (3) | R6.02, R6.07, R6.10 | Real restart/recycle, generation invalidation and real hosts |
| R7 (1) | R7.08 | Final RSS/queue/GC soak and leak attribution |
| R8 (6) | R8.02, R8.03, R8.04, R8.05, R8.07, R8.10 | Full exact-SHA certification, independent protocols/hosts and risk signoff (standalone R8.08 passed) |
| R9 (4) | R9.06, R9.07, R9.08, R9.09 | Final QA, risk, handoff; maintainer controls release |

## Active ownership / decision boundaries

**Runwire-owned engineering work:** R2.01, R6.08 and R7.09 now have focused QA evidence. R4.02/.04 and R6.02/.07 remain independently actionable without waiting for unrelated published Foundation packages. Any source optimization still needs its own end-to-end same-host proof.

**Shared performance bench:** R0.06/.07, R1.08, R2.09, R3.09, R4.01/.09 and R7.08 should reuse a single pinned runner and production-equivalent workload matrix. The 2.1.2 comparison produced zero-invalid-response paired trials but baseline coefficient of variation 4.283%, above the strict 2.5% threshold. Do not mark these tasks complete until new stable reproducible data exists.

**External dependencies:** R4.06 independent ngtcp2/aioquic interoperability passed at `f804a5a6` (PHP8.4/8.5 aioquic, PHP8.5 ngtcp2); R8.04 immutable php-quic extension ABI and R6.10 real supported hosts still need release proof, as does the R8.05 **standalone Runwire-only** exact-SHA certification. The earlier external Infbyte Composer failure is historical evidence only, not a release gate or authorization to change another repository.

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

**Non-goals:** no Foundation/Infbyte changes or mandatory consumer checks, no partial release, no weakened CV/p99/RSS correctness gates, no fake dependent Composer versions, no Rust interdependency and no automatic merge or tag.

## 2026-10-20 verification checkpoint

- Source checks added without modifying production API or dependency requirements: bounded R2 pressure profiling, fail-closed simulated privilege transitions, published-security-advisory lock scanning, and an isolated packaged-consumer smoke test.
- [R2 PHP8.4/8.5 core benchmarks 39378520084](https://github.com/infocyph/Runwire/actions/runs/39378520084) confirmed 15 scheduler pressure modes, 0 active task/timer/watcher leaks, eight-slot max-task overload rejection, 12 MiB PHP peak allocator and bounded ready queue. The tool is in-process and does not imply server RPM improvement.
- [Full PHPForge QA matrix 39379164190](https://github.com/infocyph/Runwire/actions/runs/39379164190) validated the privilege tests in PHP8.4/8.5 stable/lowest modes. Root identity transitions were simulated using `IdentitySystemInterface`; real privilege transitions remain under native host acceptance.
- [Archive smoke 39379163941](https://github.com/infocyph/Runwire/actions/runs/39379163941): clean archives from both PHP8.4 and 8.5 installed into isolated Composer consumer projects, with `ext-event` absent and `RuntimeOptions` AUTO defaults intact.
- Composer audit returned **zero advisories**, with `doctrine/annotations` documented as abandoned **without an associated published advisory**. R7.09 auditing is complete; final candidate signoff remains R9.07.

## Runwire-only release-certification isolation — 2026-10-20

- Old dependent Infbyte acceptance replaced in Runwire's own [certification workflow](https://github.com/infocyph/Runwire/commit/fd78bf9481e45dae0dfc0712760b2f6fa2724ae4) with PHP8.4/8.5 clean ZIP + Composer install + native HTTP 200/400 fixture proof. Existing Runwire-contained [Release Candidate source install](https://github.com/infocyph/Runwire/actions/runs/39425802479) passed on both PHP versions at `87cfca5`.
- [Comparator fix](https://github.com/infocyph/Runwire/commit/09817e878fd6003ed1f8c288a040396a191fe23c) removes false-positive `passed=true` for noisy paired release results. [Three regression cases](https://github.com/infocyph/Runwire/commit/87cfca5a40e9bf9a9792a1d975a408cadc1e582c) added; test-suite CI outcome is pending at this checkpoint.
- R8.05/R8.08 checkboxes remain open until Runwire's *new* release jobs and isolated native runtime prove correctness on an exact final candidate. The separately failing external dependency is not a Runwire blocker. R8 performance CV threshold is unchanged at 2.5%; prior 5 ×180s matched data failed certification.

## R8.08 accepted (2026-10-20)

At exact Runwire source [9940779](https://github.com/infocyph/Runwire/commit/99407799fdee964bc50c64ef918d8f30d05ef432), both [isolated Composer-installed native HTTP consumer jobs](https://github.com/infocyph/Runwire/actions/runs/39426401438) **passed** for PHP 8.4 and 8.5. Checks cover archive integrity, source equivalence, defaults, live H1 200/400 response correctness and process cleanup; no independent repositories are involved. PHPForge's PHP8.4/8.5 lowest/stable QA and both analysis jobs passed on [39426401968](https://github.com/infocyph/Runwire/actions/runs/39426401968). This closes standalone R8.08 only; broader runtime generation/host matrix, stable matched performance and final release certification remain open. **Live ledger: 65/95, 23 active in-scope tasks; R8 4/10.**
