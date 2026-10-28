# Runwire 3.0 — Pre-release scope expansion and architectural decisions

Prepared: 2026-10-28. Source baseline `f303ef61703d2761d9b1ab8e246ac9b1957c52a3`; keep draft PR #9 **unmerged, untagged, unpublished**. This document records improvements **beyond** the completed 167-task program. Do not retroactively redefine 167 accepted tasks as proof of new work, and do not weaken U15/U17 gates.

## Priority and decision matrix

| ID | Scope | Decision | Closure requirement |
| --- | --- | --- | --- |
| H01 | Truthful versioned feature catalog, docs and README status | MUST before 3.0 | Remove stale U15/U17 OPEN statements, preserve exclusions and exact source/proof links, GitHub Sphinx green |
| H02 | Automated release feature-claim guard | MUST before 3.0 | Source Audit checks supported/unsupported scope and 167 canonical IDs |
| H03 | Public API and behavior freeze | MUST before 3.0 | Refresh source-linked published 2.1.2 migration diff, reflect changed symbols and test dispositions, no unreviewed public breaks |
| H04 | Adversarial security/lifetime review | MUST before 3.0 | Zero known blocking SSRF, TLS/ALPN, cancellation, child-reaping, cross-tenant pooling, proxy or bounded-memory failures |
| H05 | Optional unified high-level outbound H1/H2/H3 client | ARCHITECTURAL DECISION | Implement only with consistent cancellation, session/GOAWAY and tenant identity; current H1/H2 one-shot and H3 separate APIs remain explicit, never label them unified prematurely |
| H06 | PSR-7/15/17/18 / Revolt adapters | DEFER TO OPT-IN 3.1 integration | Separate packages/namespace and no framework dependencies in base Runwire; do not create competing event loops |
| H07 | OpenTelemetry propagation hook | DEFER TO OPT-IN 3.1 integration | Explicit request/Fiber/process ownership and propagation boundaries, no core SDK dependency |
| H08 | DNS cache and in-flight coalescing | DEFER UNTIL HARDENED | Per-caller cancellation, bounded TTL/negative TTL, exact hostname/tenant/isolation, SSRF connected-peer recheck, prove no extra blocking DNS |
| H09 | Slow-peer/file/relay streaming and resource-aware autoscale | RELEASE-HARDEN | Explicitly classify experimental boundaries; add exact failure-path QA for verified production features, never claim kernel-native AIO/atomic rename |
| H10 | Final source freeze and nonpublishing release decision | MUST before 3.0 | Exact-head PHPForge QA, Source Audit, documentation, hosted/native, real H2/WS matched comparison and full 1800s mixed evidence (same implementation tree), final Release Certification green with open_tasks=0. No merge/tag/publish |

## What is already real, what is not

The branch implements native and hosted runtimes, H1/H2/H3 servers, verified H1/H2/H3 outbound protocol-specific clients, bound connection pools, proxy primitives, scoped coroutines, bounded filesystem workers, native supervised autoscale and observability. This is not a general pooled HTTP client, generic PSR stack, OpenTelemetry SDK, transparent native filesystem AIO, WebTransport or HTTPS-proxy double TLS. A missing future convenience API is not evidence that tested existing 3.0 behavior is broken.

**API governance:** Do not add a public method or class simply for an item count. Prefer minimal cohesive changes under PHPForge class <=80 / method <=12 cognitive complexity rules; no benchmarks or threshold relaxation. If H05 cannot be qualified with full session lifecycle/security and matching performance proof, keep the existing stable explicit APIs and reserve the high-level design for 3.1.

## Rolling validation

Apply H01/H02 first. Each additional source change must have focused Pest tests, complete two-PHP QA/static analysis and source-audit evidence before raising the handoff state. Re-run release certification on the final SHA. The GitHub-only 167-task tracker stays intact; these H IDs are not U tasks and must not be counted as accepted without proof.
