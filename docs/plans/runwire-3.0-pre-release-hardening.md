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

## H11 — Execution and evidence status (2026-10-28)

This additional pre-release program is separate from and does not inflate the original **167/167** accepted U tasks. **The publication gate is still CLOSED until full exact-head QA and the release-certification decision pass.**

| H item | Status | Source/proof |
| --- | --- | --- |
| H01 | ACCEPTED (docs/build) | [`b5b9b0d`](https://github.com/infocyph/Runwire/commit/b5b9b0d5381373e146c48acee0fa9f98f14a683a) and [`359bd6e`](https://github.com/infocyph/Runwire/commit/359bd6ecb37222e4270f74ed2d858a122946ae6a), strict 41-page Sphinx [#40666665830](https://github.com/infocyph/Runwire/actions/runs/40666665830) SUCCESS, [Source Audit #40666665763](https://github.com/infocyph/Runwire/actions/runs/40666665763) SUCCESS. Preserved H3, WebTransport, HTTPS-proxy and fsync exclusions. |
| H02 | ACCEPTED (automated guard) | `tools/pre-release-feature-qa.sh` executes in Source Audit, [#40666665763](https://github.com/infocyph/Runwire/actions/runs/40666665763) SUCCESS and checks accepted canonical 167 ID ledger, unavailable capabilities and outdated release-status claims. |
| H03 | SOURCE MIGRATION AUDIT ACCEPTED; final QA pending | [Source Audit #40666665763](https://github.com/infocyph/Runwire/actions/runs/40666665763) PASS: immutable 2.1.2 API manifest, property-level diff, all 35 changed published-type source hash dispositions including `SystemResourceProbe` and test-backed compatibility. Does **not** claim universal behavioral compatibility. |
| H04 | CODE/TESTS COMPLETE; full QA pending | [`510d3e0`](https://github.com/infocyph/Runwire/commit/510d3e096477084ca2cedec75cbfa9ab36158c54) canonical binary endpoint identity for IPv4-mapped IPv6 connected peers, preserve private/loopback rejection, real rebinding fail-closed, `tests/OutboundAddressPolicyTest.php`. Existing security/cancellation/native QUIC independent QA must remain green. |
| H05 | EXPLICIT 3.0 SCOPE DECISION | Existing correct and individually qualified H1/H2/H3 outbound protocol APIs stay public; do **not** fabricate high-level H1/H2/H3 automatic sessions or silently downgrade transport. A unified pooled client needs full session/tenant/GOAWAY/cancellation contract and can be added separately only after measured acceptance; not a must-have breaking change to already tested clients. |
| H06–H08 | DEFERRED BY COHESION/SECURITY DESIGN | Optional PSR/Revolt, tracing SDK and DNS cache/coalescing require separately owned framework/tenant cancellation and cache semantics. No extra core dependency, unsound pooling, or unqualified public ABI introduced during release freeze. |
| H09 | CODE/TESTS COMPLETE; full QA pending | [`e12d90c`](https://github.com/infocyph/Runwire/commit/e12d90cf79b762a0ec2d43076f821f1c6886191c) cgroup cpuset duplicate/overlap/overflow rejection and fail-safe one-CPU fallback with fractional-quota tests, `tests/SystemResourceProbeCgroupTest.php`; independent 1800s mixed/autoscale and slow-receiver/relay qualification retain their previous scope. |
| H10 | PENDING | Exact last immutable SHA PHPForge/Sphinx/source/portable/host/native plus freshly pinned opt-in 5×180s H2/WS, true 1800s mixed and Release Certification `certification_ready=true`, `open_tasks=0`, `release_tag_created=false`, `package_published=false`. |

**Release governance:** H05–H08 are architecture decisions, not falsely accepted implementations. This freeze covers demonstrable correctness, documentation and security improvements before 3.0. Any high-level API expansion requires a new explicit acceptance program, not reusing U17 proof. PR remains draft. No merge, tag, deployment, release or publication.
