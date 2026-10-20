# Runwire 2.2 — draft maintainer release handoff

**Status:** NOT READY. This document is a live handoff, not release approval.

**Branch / PR:** `plan/runwire-independent-ecosystem-roadmaps` / [#9](https://github.com/infocyph/Runwire/pull/9). **Comparison baseline:** `2.1.2` at `7e9bf20ad0737a187dfed77b723791b141b36378`. **Candidate SHA:** resolve the exact current source commit from PR #9 after the last code and docs change; do not use a stale hash from this draft.

## Required before READY FOR MAINTAINER RELEASE

- [ ] Freeze final exact SHA and generated dependency lock/toolchain versions, record full diff and reproducible source baseline.
- [ ] Full PHP8.4/8.5 QA, analysis, format, source/security audit and release guard, stable + lowest dependencies.
- [ ] Portable fallback no-PCNTL/POSIX, accelerated ext-event, QUIC/OpenSSL, HTTP1/2/3 independent clients, WebSocket, TCP/Unix, UDP, hosted lifecycle and real Swoole/OpenSwoole workers.
- [ ] Matched published 2.1.2 baseline and candidate on identical host: 5 ×180s trials after warmup, stable successful RPM (CV below 2.5%), no more than 5% rate regression; enforce p99, RSS, correctness and overload ceilings. Avoid cross-CPU runner comparisons.
- [ ] Fresh 30-minute native/persistent worker soak with response completeness, p99, queues, CPU, actual RSS/FDs and no retained request state.
- [ ] Infbyte consumer suite and Foundation/Webrick bridge on actually available compatible dependencies; this currently blocks.
- [ ] Close remaining R0–R9 unchecked functional/qualification tasks, or explicitly record accepted deferred scope without claiming those tasks completed.
- [ ] Reconcile all QA skip/failure and intermittency findings, advisory checks, user-facing docs and exact source SHA artifacts.
- [ ] Confirm zero unresolved high-severity security/protocol correctness defects; PR reviewed and release notes finalized.

## Maintainer-controlled actions only

Once every required gate is green for a single exact SHA, the maintainer may review/merge PR #9, create the appropriate 2.2 tag, publish Composer metadata and release notes and check downstream adoption separately. This assistant has **not** performed any of those actions.

## Open risks

1. Previous pair A/B release trial was correct but unstable (baseline CV 4.283%). No certified throughput benefit.
2. Source runner cgroup quotas may be N/A. Never assume no memory/CPU limit; capture actual host settings in the final benchmark.
3. Consumer dependency `infocyph/foundation:^3.0.1` currently not resolvable from published versions.
4. Final exact-SHA optional host/protocol tests and 30-minute soak remain unverified.
5. New connect/dialer work intentionally deferred; do not announce it as a 2.2 capability.

## R9.05 clean archive acceptance completed — 2026-10-20

[Release Candidate 39379163941](https://github.com/infocyph/Runwire/actions/runs/39379163941) passed on PHP 8.4 and 8.5 using the generated Composer ZIP. Both jobs extracted the package, confirmed required source/package metadata and the absence of tests/vendor, installed Runwire into a separate fresh Composer consumer via a path repository with the untagged **candidate's explicit dev version**, compared the installed `RuntimeOptions.php` source to the archived source, autoloaded `Runtime`, and checked safe AUTO runtime/OPcache defaults. This proves a consumer installation without `ext-event`, even though Runwire's root development dependencies include that optional extension. It is a candidate smoke test, **not** the unpublished Foundation/Infbyte consumer acceptance required by R8.05 and R8.08.
