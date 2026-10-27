# Runwire 3.0 — C5 source-frozen release risk register (pre-signoff)

**Status:** PRELIMINARY, NOT MAINTAINER-APPROVED. No U17 checkbox is accepted by this document.
**Prepared:** 2026-10-27. **Target:** unreleased 3.0.0, existing draft PR #9.
**Published comparator:** Runwire 2.1.2, `7e9bf20ad0737a187dfed77b723791b141b36378`.
**Candidate:** an immutable SHA must be explicitly selected only after C4 closure. A moving branch head is not certification.
**Boundaries:** Runwire repository and GitHub-hosted QA only; no merge, tag, package publish, other-repository edits or security gate waivers.

## Required C5 evidence and release authority

| U17 ID | Proof required for a frozen candidate | Decision |
| --- | --- | --- |
| U17.01 | Published 2.1.2 and unfinished 2.2 crosswalk to every 3.0 feature and exclusion; source-audit-verified 20/20 mapping | ACCEPTED scoped 20/20 crosswalk, 167-task Source Audit #40542104606 |
| U17.02 | PHP 8.4/8.5 stable/lowest PHPForge, PHPStan/Psalm, PHPCS/Pint/Rector and security QA | OPEN until exact frozen-head all green |
| U17.03 | Independent H1/H2/H3/QUIC/WS, native portable/fork, real hosted peers and shutdown correctness | OPEN until exact frozen-head matrix |
| U17.04 | GitHub Actions complete warning-free Sphinx HTML, generated API, 41 indexed/searchable pages and runnable examples with source SHA | ACCEPTED: exact Sphinx #40542561935 and 41-page HTML artifact #12751068496 |
| U17.05 | Standalone Composer archive install on PHP 8.4/8.5, host/optional extension contract | OPEN until exact frozen-head archive proof |
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
