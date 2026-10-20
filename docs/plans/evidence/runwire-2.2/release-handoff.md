# Runwire 2.2 — draft maintainer release handoff

**Status:** NOT READY. This document is a live handoff, not release approval.

**Branch / PR:** `plan/runwire-independent-ecosystem-roadmaps` / [#9](https://github.com/infocyph/Runwire/pull/9). **Comparison baseline:** `2.1.2` at `7e9bf20ad0737a187dfed77b723791b141b36378`. **Candidate SHA:** resolve the exact current source commit from PR #9 after the last code and docs change; do not use a stale hash from this draft.

## Required before READY FOR MAINTAINER RELEASE

- [ ] Freeze final exact SHA and generated dependency lock/toolchain versions, record full diff and reproducible source baseline.
- [ ] Full PHP8.4/8.5 QA, analysis, format, source/security audit and release guard, stable + lowest dependencies.
- [ ] Portable fallback no-PCNTL/POSIX, accelerated ext-event, QUIC/OpenSSL, HTTP1/2/3 independent clients, WebSocket, TCP/Unix, UDP, hosted lifecycle and real Swoole/OpenSwoole workers.
- [ ] Matched published 2.1.2 baseline and candidate on identical host: 5 ×180s trials after warmup, stable successful RPM (CV below 2.5%), no more than 5% rate regression; enforce p99, RSS, correctness and overload ceilings. Avoid cross-CPU runner comparisons.
- [ ] Fresh 30-minute native/persistent worker soak with response completeness, p99, queues, CPU, actual RSS/FDs and no retained request state.
- [x] Runwire-only standalone consumer acceptance at tested Runwire source `9940779`: clean archive, copied Composer install and source verification on PHP8.4/8.5, plus real native HTTP 200/400 correctness. [Both CI jobs passed](https://github.com/infocyph/Runwire/actions/runs/39426401438). Foundation and Infbyte are independent and are **not** certification dependencies. Full final-source release certification remains separate.
- [ ] Close remaining R0–R9 unchecked functional/qualification tasks, or explicitly record accepted deferred scope without claiming those tasks completed.
- [ ] Reconcile all QA skip/failure and intermittency findings, advisory checks, user-facing docs and exact source SHA artifacts.
- [ ] Confirm zero unresolved high-severity security/protocol correctness defects; PR reviewed and release notes finalized.

## Maintainer-controlled actions only

Once every required gate is green for a single exact SHA, the maintainer may review/merge PR #9, create the appropriate 2.2 tag, publish Composer metadata and release notes and check downstream adoption separately. This assistant has **not** performed any of those actions.

## Open risks

1. Previous pair A/B release trial was correct but unstable (baseline CV 4.283%). No certified throughput benefit.
2. Source runner cgroup quotas may be N/A. Never assume no memory/CPU limit; capture actual host settings in the final benchmark.
3. Historical Infbyte dependency resolution failed because its independent Foundation constraint was unavailable; this is **not a Runwire release blocker**. Replacement standalone acceptance still needs exact-SHA QA.
4. Final exact-SHA optional host/protocol tests and 30-minute soak remain unverified.
5. New connect/dialer work intentionally deferred; do not announce it as a 2.2 capability.

## R9.05 clean archive acceptance completed — 2026-10-20

[Release Candidate 39379163941](https://github.com/infocyph/Runwire/actions/runs/39379163941) passed on PHP 8.4 and 8.5 using the generated Composer ZIP. Both jobs extracted the package, confirmed required source/package metadata and the absence of tests/vendor, installed Runwire into a separate fresh Composer consumer via a path repository with the untagged **candidate's explicit dev version**, compared the installed `RuntimeOptions.php` source to the archived source, autoloaded `Runtime`, and checked safe AUTO runtime/OPcache defaults. This proves a consumer installation without `ext-event`, even though Runwire's root development dependencies include that optional extension. The new Runwire-only R8.05/R8.08 acceptance requires additional real-native HTTP fixture QA on exact candidate SHA; it no longer requires the independent Foundation/Infbyte consumer.

## Runwire-only QA boundary implemented — 2026-10-20

Runwire [certification job `fd78bf9`](https://github.com/infocyph/Runwire/commit/fd78bf9481e45dae0dfc0712760b2f6fa2724ae4) now performs a clean archive install with candidate source checks and real native HTTP/1.1 200/400 response validation on PHP 8.4 and 8.5, rather than checking out or modifying independent consumers. The benchmark [variance verdict fix](https://github.com/infocyph/Runwire/commit/09817e878fd6003ed1f8c288a040396a191fe23c) retains the strict 2.5% CV gate. [Source Audit](https://github.com/infocyph/Runwire/actions/runs/39425802729) and [Release Candidate](https://github.com/infocyph/Runwire/actions/runs/39425802479) passed at `87cfca5`; the new isolated certification and complete QA are **not yet verified**. No release actions authorized.

## Individual R8.08 standalone acceptance — passed

PHP8.4 and PHP8.5 `Standalone Runwire package / native HTTP` jobs [39426401438](https://github.com/infocyph/Runwire/actions/runs/39426401438) both succeeded on `9940779`, proving live packaged Runwire HTTP/1.1 responses in an independent Composer consumer, plus source archive parity and process cleanup. Broader H1/H2/H3, hosted-driver and recycle acceptance is still tracked under R6/R8.04; paired benchmark CV remains a hard gate. No Foundation or Infbyte changes were made.

## R8.04 accepted independent network matrix, but not release certification


### Verified R8.04 — Runwire-only integration matrix (2026-10-20)

All six standard workflows were green on immutable source [1770ba8](https://github.com/infocyph/Runwire/commit/1770ba82cd8be96816d673237cc0e2cac8ccb0ac). [Benchmarks run 39433339538](https://github.com/infocyph/Runwire/actions/runs/39433339538) exercised native HTTP/1.1, independent Python h2 real TLS multiplexed requests, and native WebSocket upgrades/framing. [Security & Standards 39433340291](https://github.com/infocyph/Runwire/actions/runs/39433340291) passed real QUIC/OpenSSL HTTP3 native server interoperability with independent aioquic on PHP8.4/8.5 and ngtcp2 on PHP8.5. [Portable Native 39433339585](https://github.com/infocyph/Runwire/actions/runs/39433339585) passed PHP8.4/8.5 real HTTP/TCP/Unix/UDP/TLS local-response tests in truly no-PCNTL/POSIX mode. [Swoole/OpenSwoole 39433339597](https://github.com/infocyph/Runwire/actions/runs/39433339597) passed live multiworker lifecycle and identity checks with both extension families.

**R8.04 passed**, as bounded actual network/transport integration—not as release performance certification. H2 comparison JSON is explicitly `diagnostic=true`, `promotion_certified=false`: 1s warmup/5s phases and noisy throughput do not meet the strict 30s warmup, 5×180s, 2.5% CV release threshold. Real FrankenPHP/RoadRunner are not available in current CI and remain **open R6.10**; mixed multi-protocol native prefork rolling replacement remains **open R6.02**. R4.01/.02/.04/.09 and R8.05/.07 stay open. The newer 30-minute soak RSS/FD sampler is implemented but not yet run and accepted; R7.08 remains open, including unavailable external GC/OPcache attribution.


## Final QA matrix / exact-source release gate

See [the Runwire-only final qualification matrix](final-qa-matrix.md) for all accepted CI modes, 19 still-open tasks, R5 deferrals, archival consumer dependency note and exact-source guard. Source [d2646e2](https://github.com/infocyph/Runwire/commit/d2646e2919317cc376f19fd1237affb1dbc87679) fixes the new resource profiler's PHPForge lint policy issues; do not mark its final CI as green until checks conclude. Live 30-minute RSS/FD sampling and paired stable CV <2.5% performance gate remain unverified on one frozen candidate. This remains **NOT READY FOR MAINTAINER RELEASE**.

## R9.08 release handoff assembled — 2026-10-20

**Preparation checkpoint:** Runwire code source [93416ea](https://github.com/infocyph/Runwire/commit/93416eaabd367146e565d3e0752ed7cf1f992a37) and canonical documentation checkpoint [82eef9e](https://github.com/infocyph/Runwire/commit/82eef9e3e8e0533c7e35ca9d7d154267d7e0602c). **Before any release, the maintainer must record the final source SHA after the very last change and re-run all required gates on that SHA.** A documentation or PR-merge SHA must never be silently substituted for the exact tested candidate SHA.

**Release comparison:** Immediate published predecessor [2.1.2](https://github.com/infocyph/Runwire/releases/tag/2.1.2) at `7e9bf20ad0737a187dfed77b723791b141b36378`. [Full commit-by-commit/diff comparison](https://github.com/infocyph/Runwire/compare/2.1.2...plan/runwire-independent-ecosystem-roadmaps) and the curated [2.2 release-notes draft](../../../release-notes-2.2.md) together form the maintained changelog and implementation scope. [Canonical tracker](../../runwire-2.2-runtime-improvement-plan.md), [full per-mode QA matrix](final-qa-matrix.md), [critical-path blockers](closure-critical-path.md), and [R5/R6 host gap details](r5-r6-handoff.md) are authoritative for this handoff.

**Acceptance methodology:** same actual runner topology for 2.1.2 baseline and candidate; PHP 8.5, OPcache enabled, matched dependencies/extension versions, one worker, 16 persistent clients, warmed 30 seconds per runtime, five 180-second trials per side, alternating comparison order, correct complete HTTP 200 responses, zero timeout/validation errors. Candidate median successful RPM must not regress more than 5%, **both baseline and candidate RPS CV must remain below 2.5%**, and p99/RSS ceilings must pass `benchmarks/regression_compare.php` (the CI command fails closed on inconclusive variance). Separate 30-minute native HTTP run uses 32 concurrent connections, plus 15-second process RSS/FD samples; incomplete or growing resource windows must fail. Benchmark report is a gate, **not a claim of observed performance gain**.

**Still unaccepted:** sustained R0/R1/R3/R4 performance attribution and H2/H3 pressure/slow-peer storms; real native prefork mixed-protocol worker crash/rolling-restart cycle (R6.02); real FrankenPHP and RoadRunner acceptance (R6.10); long-running GC/OPcache/queue reference and RSS/FD retention (R7.08); exact-final-SHA matched benchmark and release certification (R8.05/R8.07), R8.10 final signoff, R9.06/R9.07 release blocker review. The existing Runwire Core and Cells Rust proposals and the R5.02–.08 outbound dialer remain independent/deferred, **not** required for 2.2. There is **no** Foundation or Infbyte release/CI dependency and neither repository is modified.

**Maintainer-only release commands**, to run **only after all mandatory checks have passed on the frozen SHA**:

```bash
git fetch origin --tags
git checkout plan/runwire-independent-ecosystem-roadmaps
git pull --ff-only origin plan/runwire-independent-ecosystem-roadmaps
candidate_sha="$(git rev-parse HEAD)"
printf 'Candidate source SHA: %s\n' "$candidate_sha"
git log --oneline 2.1.2.."$candidate_sha"
git diff --check 2.1.2.."$candidate_sha"
composer validate --strict --no-check-publish
composer audit --locked --abandoned=report
# Validate PR #9's six standard QA workflows and the opt-in Release Certification
# workflow against candidate_sha; attach run IDs, full artifacts and risk signoff.
# Do not merge/tag/publish unless every R8/R9 release gate has been explicitly approved.
```

When all gates have been evidenced and the maintainer explicitly approves: merge PR #9, tag the actual merged release commit as directed by the maintainer, publish the Composer/GitHub release with the release notes, and coordinate downstream adoption **separately**. These are instructions, **not actions performed**. Current handoff remains **NOT READY**.


### R7/R8 final-certification evidence and process-tree sampler correction — 2026-10-20

- [Opt-in Release Certification 39438778354](https://github.com/infocyph/Runwire/actions/runs/39438778354) completed on **exact Runwire candidate `d69ddfa7b2af49a03c3b648c81348ef54ef005d7`**. **Four of five jobs passed**: [30-minute HTTP/1.1 soak artifact 12274868566](https://github.com/infocyph/Runwire/actions/runs/39438778354/artifacts/12274868566), clean PHP 8.4+8.5 Composer/native HTTP smoke, and PHPForge ext-event release guard. **Matched-performance job failed the strict CV gate.** This is a failed release-certification run, not an accepted speedup.
- **Five 180-second paired A/B trials** against published 2.1.2 at `7e9bf20`, same AMD EPYC 9V45 runner, PHP 8.5.11, one worker/16 keep-alive clients, 30-second runtime warmups, alternating order. [Artifact 12273863432](https://github.com/infocyph/Runwire/actions/runs/39438778354/artifacts/12273863432): baseline median **19,515.247 successful RPS**, candidate **20,216.440** (**+3.593% observed**, not certified); baseline RPS CV **2.984%** versus candidate CV **1.325%**, so max **2.984% exceeds strict <2.5%**. p99 1.86→1.85ms, peak process-tree RSS 129,458,176→122,540,032 bytes; no correctness, p99, RSS or -5% regression-budget failure. Comparator correctly reports `budget_enforced=false`, `passed=false`, `certification_status=inconclusive_variance`.
- **30-minute soak passed**: **11,141,982 / 11,141,982** requests complete and successful, zero errors/timeouts/validation failures, one worker/32 keep-alive clients, p99 10.97ms, benchmark process-tree peak RSS **121,372,672 bytes**. Prior [RSS/FD resource sampler artifact](https://github.com/infocyph/Runwire/actions/runs/39438778354/artifacts/12274868566) had 122 observations across 1815 seconds, root PID RSS early/late median 78,938,112 bytes and root FD median 12, no observed growth. **Critical limitation:** original sampler covered only the supervisor/root PID while the HTTP benchmark reported process-tree peak; root-only stability does not prove child worker RSS/FD stability.
- **Runwire-only correction:** [code `8828851`](https://github.com/infocyph/Runwire/commit/8828851a65d6d77924f0311780e2ee439f3c706e) makes the external sampler recursively collect the supervisor and descendants' process RSS/virtual memory, FD count, and number of live worker processes, with explicit drift/coverage guards. Summed VmRSS is **not PSS** and can double-count shared mappings. [PHPForge no-skip fix `7609bed`](https://github.com/infocyph/Runwire/commit/7609bed1146cf6147506afb0ddde3a1ec2d808c2) replaces an unsupported test skip; **full six-workflow QA and a fresh 30-minute process-tree soak on the corrected head are still pending** at this checkpoint. PHP GC/OPcache *inside worker processes* and request-reference retention remain unmeasured by this external sampler.
- **Canonical status remains 71/95 checked, 7 R5 deferred, 71/88 in scope complete (80.7%), 17 active open.** No R7.08/R8.05/R8.07 final-qualification checkbox is checked merely because an earlier run contained individual passing jobs. The opt-in certification must be rerun on one **frozen corrected Runwire head**, with CV <2.5% on both paired populations and all gates passed. Foundation and Infbyte are entirely independent, untouched, and not in the release verdict. No merge/tag/publication.

### PHPForge QA regression resolved — 2026-10-20 finalization pass

- **Exact fault:** [Security & Standards 39453035106](https://github.com/infocyph/Runwire/actions/runs/39453035106) had four red stable/lowest QA jobs due solely to PHPForge Pint `blank_line_before_statement` in the new Runwire-owned `benchmarks/soak_runtime_telemetry.php`. The independently triggered [release guard 39453076098](https://github.com/infocyph/Runwire/actions/runs/39453076098) likewise failed this formatting gate after 605 Pest tests passed (4,071 assertions); the packaged PHP8.4/8.5 native H1 consumers passed. **This older certification attempt is NOT qualifying evidence** regardless of its other jobs.
- **Runwire fixes:** [`301027e`](https://github.com/infocyph/Runwire/commit/301027e495e35b88604981c77c3430cfe93ee03d) corrected the required blank line before `try`, and [`88f1e74`](https://github.com/infocyph/Runwire/commit/88f1e74f1a2a7fa59103a9f6010cb5cf46dceb31) added the PHPForge-required blank line before `break` in invalid telemetry handling. No formatter, plugin, QA threshold or external repository was modified.
- **Post-fix six-workflow standard QA:** [Security & Standards 39457773144](https://github.com/infocyph/Runwire/actions/runs/39457773144) passed **all four PHP8.4/8.5, lowest/stable Pest+Pint+PHPCS jobs**, both static-analysis jobs, PHP8.4 QUIC, and the ordinary clean-install/bench jobs at tested source `88f1e74`. HTTP/3 PHP8.5 remained in progress at this checkpoint. [Release Candidate 39457772634](https://github.com/infocyph/Runwire/actions/runs/39457772634), [Source Audit 39457772797](https://github.com/infocyph/Runwire/actions/runs/39457772797), [Portable Native 39457772541](https://github.com/infocyph/Runwire/actions/runs/39457772541), and [Swoole/OpenSwoole 39457772573](https://github.com/infocyph/Runwire/actions/runs/39457772573) passed; [Benchmarks 39457772574](https://github.com/infocyph/Runwire/actions/runs/39457772574) had both PHP8.4/8.5 host/protocol core jobs passed with a final adaptive diagnostics job still in progress.
- **No premature task closure:** Canonical **72/95 checked, seven R5 tasks deferred, 72/88 in-scope complete (81.8%), 16 active open**. The 30-minute full process-tree+PHP GC/OPcache instrumented soak has not yet succeeded on a final immutable source. Previous noisy five ×180s baseline/candidate A/B remained inconclusive; do not lower the **2.5%** CV ceiling. Real unsupported host evidence (FrankenPHP/RoadRunner) and mixed-protocol prefork lifecycle checks remain open R6.02/R6.10. R7.08/R8.05/R8.10/R9 cannot be marked READY. **Only Runwire touched.** No merge, tag, or publish.
