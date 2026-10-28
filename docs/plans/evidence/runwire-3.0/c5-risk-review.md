# Runwire 3.0 — C5 source-frozen release risk register (pre-signoff)

**Status:** PRELIMINARY, NOT MAINTAINER-APPROVED. The canonical plan accepts U17.01/.02/.03/.04/.05 on separately verified GitHub-worker evidence; this risk review does not itself authorize remaining tasks or release.
**Prepared:** 2026-10-27. **Target:** unreleased 3.0.0, existing draft PR #9.
**Published comparator:** Runwire 2.1.2, `7e9bf20ad0737a187dfed77b723791b141b36378`.
**Candidate:** an immutable SHA must be explicitly selected only after C4 closure. A moving branch head is not certification.
**Boundaries:** Runwire repository and GitHub-hosted QA only; no merge, tag, package publish, other-repository edits or security gate waivers.

## W222 — Evidence-backed release blockers and non-waiver disposition (2026-10-28)

- **C4 duration/correctness BLOCKER:** [#40630879058](https://github.com/infocyph/Runwire/actions/runs/40630879058) PHP8.5 early abort (16 response-read errors, libevent callback RuntimeException); failed mandatory 1800s resource/worker evidence. Source `d0d8c3c` now publishes actual elapsed and fails early completion; opt-in callback stack will identify underlying exception on next run. Cannot sign off U15.10 or U17.06.
- **C5 independent H2 client CPU BLOCKER:** [#40629958832](https://github.com/infocyph/Runwire/actions/runs/40629958832) observed 92–94% independent Python client CPU against preexisting <85% headroom. No weakening of 5×180s, real response correctness, p99, variance or RSS checks. Reuse immutable H2 request/payload templates in both candidate and published comparator is a generator-side resource improvement to verify, not proven success.
- **PHPForge tracked regression FIX SUBMITTED:** [#40632686371](https://github.com/infocyph/Runwire/actions/runs/40632686371) four QA lanes failed only on stale U17.02 checkbox assertion; test fixed by [`1929d52`](https://github.com/infocyph/Runwire/commit/1929d5220862d114021295b3ff31ce30b48714c5). Full QA must pass at final head.
- **Release disposition:** accepted earlier U17.01/.02/.03/.04/.05 are retained as historically evidenced; **U17.06/.07/.08/.09 and C4 U15.10 remain OPEN**, zero authority to publish. Candidate source changed after W221 and needs fresh exact-head qualification. No exceptions, plan deletion, threshold tweaks, placeholders, or publishing signoff.

## W221 — C5 accepted work and outstanding final release gates (2026-10-28)

**ACCEPTED on immutable source `9fa4d6ce03c000d61ae3a8f402aa6e5f9d332f8f` (production src/ tree `93565e6bfd33f4537672b8b603307c7b4821fc09`):** U17.02 PHPForge full PHP8.4/8.5 QA/analysis/security [#40630724053](https://github.com/infocyph/Runwire/actions/runs/40630724053); U17.03 independent H1/H2/H3/QUIC/WebSocket native + hosted functional peer matrix [#40630723700](https://github.com/infocyph/Runwire/actions/runs/40630723700), [#40630724053](https://github.com/infocyph/Runwire/actions/runs/40630724053), [#40630723584](https://github.com/infocyph/Runwire/actions/runs/40630723584), [#40630723620](https://github.com/infocyph/Runwire/actions/runs/40630723620), [#40630723566](https://github.com/infocyph/Runwire/actions/runs/40630723566), [#40630723561](https://github.com/infocyph/Runwire/actions/runs/40630723561); U17.05 isolated PHP8.4/8.5 Composer archives + optional event/QUIC and real hosted matrices [#40630723567](https://github.com/infocyph/Runwire/actions/runs/40630723567). U17.01/.04 previously accepted.

**STILL OPEN:** C4 U15.10 (mixed real 1800s on both PHP versions), C5 U17.06–09 (five-pair and actual soak, full final risk verification, immutable QA/report reconciliation, maintainer handoff). Standalone functional protocol acceptance **does not** certify separate matched H2/WS 5×180s performance or final whole-tree soak. This is a source-only task-record update; no production source changed. The all-task release gate must remain red until all remaining tasks are evidenced. Maintainer publication approval is not supplied; PR draft, unmerged, untagged and unpublished.

## W220 — QA and frozen-source handoff update (2026-10-28)

**Pre-signoff and all eight tasks remain OPEN, except already-accepted U17.01/U17.04.** PHPForge Security & Standards [#40629832470](https://github.com/infocyph/Runwire/actions/runs/40629832470) exposed PHPStan `ByteQueue::read()` cognitive complexity 14 against max 12 and Pint `EventLoop.php` ordered-class-elements; failed 8.4/8.5 stable/lowest and analysis lanes are **real release blockers**. Corrective next-source commit must re-run the complete same QA without suppression. Earlier 4d1b5b4 1800s mixed soak, independent H2/WS and release-certification runs are not final evidence for any subsequent changed candidate. Do not claim U15.10, U17.02, U17.03, U17.05, U17.06 or U17.07–09 accepted until their exact frozen-source requirements finish. No maintainer release signoff supplied.

## Required C5 evidence and release authority

| U17 ID | Proof required for a frozen candidate | Decision |
| --- | --- | --- |
| U17.01 | Published 2.1.2 and unfinished 2.2 crosswalk to every 3.0 feature and exclusion; source-audit-verified 20/20 mapping | ACCEPTED scoped 20/20 crosswalk, 167-task Source Audit #40542104606 |
| U17.02 | PHP 8.4/8.5 stable/lowest PHPForge, PHPStan/Psalm, PHPCS/Pint/Rector and security QA | ACCEPTED: exact source `9fa4d6c`, Security & Standards #40630724053 all required QA/analysis/QUIC jobs SUCCESS |
| U17.03 | Independent H1/H2/H3/QUIC/WS, native portable/fork, real hosted peers and shutdown correctness | ACCEPTED functional interoperability: source `9fa4d6c`, #40630723700, #40630724053, #40630723620, #40630723584, #40630723566, #40630723561; **U17.06 performance still OPEN** |
| U17.04 | GitHub Actions complete warning-free Sphinx HTML, generated API, 41 indexed/searchable pages and runnable examples with source SHA | ACCEPTED: exact Sphinx #40542561935 and 41-page HTML artifact #12751068496 |
| U17.05 | Standalone Composer archive install on PHP 8.4/8.5, host/optional extension contract | ACCEPTED: source `9fa4d6c`, archive consumer #40630723567, portable/accelerated #40630723584, PHPForge Composer and QUIC #40630724053, hosted #40630723620 |
| U17.06 | Immutable 2.1.2-vs-3.0 five paired 180s comparison and continuous real 1800s whole-process soak | OPEN; short-lived or cancelled runs cannot substitute |
| U17.07 | Independent fuzz/security/performance/migration risk review with explicit maintainer decision | OPEN; approval not supplied |
| U17.08 | All published issue/PR/plan claims linked to exact QA job, SHA and immutable evidence artifact | OPEN until final reconciliation |
| U17.09 | PR remains draft, unmerged, untagged, unpublished until explicit maintainer release instruction | OPEN until all requirements accepted |

## Scope and residual risk review

| Boundary | Verified development evidence | Remaining release risk / required disposition |
| --- | --- | --- |
| Native HTTP/3, QUIC and QPACK | U07/U08 task-specific independent aioquic/ngtcp2 and PHP 8.4/8.5 acceptance recorded in the canonical development plan | ext-quic/OpenSSL availability is optional; require final frozen SHA and independent peer acceptance under U17.03 |
| HTTP/1.1, HTTP/2, WebSocket | Task-specific real socket, TLS, HPACK, multiplex, masking, cancellation and backpressure QA in U07–U09 | Re-run exact final supported-mode lifecycle and throughput tests; do not infer published 2.1.2 baseline for 3.0-only protocols |
| WebTransport | Deliberately and completely excluded from 3.0; not covered by native QUIC datagrams | Never advertise WebTransport, RFC 9221 DATAGRAM semantics or silent stream substitutes; feature inventory stays explicit |
| HTTPS proxy with nested TLS | U10.06 accepts original-origin verified TLS over cleartext CONNECT and fail-closed behavior on HTTPS-proxy nesting | No TLS-over-TLS support claim; require preserved negative tests and clear migration/feature notices |
| Filesystem | Bounded subprocess-backed off-loop I/O; U11 process/resource/loop-tick QA | No kernel-native AIO, race-free sandbox, crash-atomic replace or fsync promise |
| Native supervisor and autoscale | C3 9/9 accepted; independent mixed H1/H2/H3/TCP/UDP SIGTERM/crash/recycle testing | Requires native PCNTL/POSIX; verify no process leaks after real 1800s mixed soak and exact frozen-head fault suite |
| Performance comparisons | U15.09 scoped 2.1.2 vs 3.0 plaintext H1 -3.288% successful RPS, within declared -5% gate; verified-TLS H1 matched comparisons passed | No universal 3.0 speedup claim, no baseline fiction for new H2/H3/WS; full mixed 1800s U15.10 and final U17.06 still OPEN |
| Instrumentation | U15.03 measured actual native/hosted heavy strace overhead with large throughput reduction | strace is diagnostic-only, not production always-on tracing; final benchmark must remain untraced |
| Documentation | Strict source-aligned Sphinx builds and source-revision-stamped 41-page/search inventory | GitHub-worker HTML artifact and examples remain U17.04; external hosted RTD U16.02/U16.16 retired from active scope, not declared passed |
| Migration | U16.12 tested 32 source-changed public implementations and six constructor/two trait cases against immutable 2.1.2 | This is a source/test disposition ledger, not unqualified behavior/binary compatibility or U17 release signoff |
| Package integrity | Regular Release Candidate workflow exercises isolated Composer archive, native HTTP, and safe defaults | Final immutable candidate PHP8.4/8.5 packaging and optional extension matrix remains U17.05 |

## Fail-closed evidence policy

A QA run proves only the commit, environment and exact workload it identifies. Cancelled, skipped, failed, incomplete or mismatched-SHA jobs provide no task acceptance. Passing a locally generated Sphinx artifact does not establish hosted Read the Docs behavior. A task can move from OPEN only after its required proof is confirmed and the canonical checklist, roadmap, PR description and this register are synchronized without status inflation. Maintain independent evidence from source, tests, real protocol peers, host runtimes and release benchmarks.

**Maintainer review:** PENDING. No release vote, signature, signoff or authorization is implied by the presence of this register.
