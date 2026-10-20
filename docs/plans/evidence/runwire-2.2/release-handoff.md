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
