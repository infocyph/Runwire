# Runwire production feedback remediation

Base: 970e6f05f1ae5b5082cd3b4a329116d1d7a42325 (3.1 baseline). Proposed compatible patch: 3.1.1; no release or version change authorized.

Sources: [consumer feedback](https://github.com/infocyph/infbyte-app-experiment/blob/main/feedbacks/runwire.md), [PHPForge engineering principles](https://github.com/infocyph/PHPForge/blob/main/resources/engineering-principles.md).

Inventory: 451 src files, 360 test-tree files, 969 tracked files. Initial inventory and targeted trace are complete; exhaustive review is not. Record actual class/file deltas and benchmarks; prefer consolidating internal single-owner helpers only when ownership, API, and hot-path cost remain sound.

## Action register

- [ ] A / RUN-001: SETTINGS role, malformed frames and independent TLS/ALPN reference. Source fix and regression tests implemented; six hosted workflows passed on 2b46a98, including independent hyper-h2 TLS (PHP 8.4–8.6); Security & Standards still running at last observation. Restore unmodified hyper-h2 server SETTINGS (previous fixtures incorrectly removed ENABLE_PUSH=0). Verify exact peer SETTINGS, no downgrade, rejection of nonzero values, correct connection error handling on wire.
- [ ] B / RUN-002: Re-run multiplex/flow/PING/deadline, verify same socket, independent stream settlement, HEAD, RST, GOAWAY, flow credit, cleanup. Do not count cascade as independent defects. Batch B1: preserve timeout/token failure reason, fail closed if cancellation cannot settle, centralize HEAD body validation, remove redundant retained request envelope per active stream. Hosted verification pending; further GOAWAY, late-frame and consumer acceptance remain.
- [ ] C / RUN-003: Retest corrected consumer capture; verify H1 retry callback semantics, framing, H2 HEAD/disconnect, H3 parity and redirect ownership. Query-only redirect already fixed on main. No blind replay or speculative buffering.
- [ ] D / RUN-005: Verify DNS candidate and actual connected peer identity, TLS name, redirects, H1/H2/H3, Epicrypt integration; fail closed.
- [ ] E / RUN-004 and RUN-006: Trace supervisor, executor, host and coroutine lifecycle, cancellation/reaping, backpressure, native QUIC. Foundation drain and TalkingBytes rate/circuit reports are ownership candidates, not proven Runwire bugs.
- [ ] F / standards and structure: review all 13 PHPForge sections against every source subsystem, test and workflow; consolidate only demonstrably unnecessary internal classes/files; record review coverage, before/after count, public API and performance evidence.
- [ ] G / consumer acceptance: 307 runtime tuples, six-host checks, corrected fixtures, exact package/version evidence; PHP 8.5 published consumer matrix and supported upstream PHP 8.4–8.6.
- [ ] H / release certification: independent peers, sustained throughput, p95/p99, memory, leak checks; all applicable hosted CI green, review feedback resolved, no auto-merge.

## Execution

One logical batch per commit, 50–100 reviewed files where meaningful (not artificial modifications); hosted workers only. Keep tracker and PR feedback synchronized. No new skips, suppressions, dependency revision pins, vendor changes, security bypasses, or weakened assertions. Preserve public API. Separate third-party/consumer fixes from Runwire. Functional correctness precedes performance. Existing CI success is not proof of defect-free behavior.

## Evidence

RUN-001: [strict H2 job](https://github.com/infocyph/infbyte-app-experiment/actions/runs/45756151480/job/139695176565) reports unconditional rejection of ENABLE_PUSH in Runwire 3.1; RFC 9113 section 6.5.2 permits zero. Both independent peer fixtures suppressed the default zero setting, masking interoperability. Batch A restores it and tests initial and subsequent SETTINGS, invalid values and malformed frames. Independent reference passed; full standards and wire-level negative peer verification remain pending.
