# Runwire 3.0 — Pre-release scope expansion and architectural decisions

Prepared: 2026-10-28. Source baseline `f303ef61703d2761d9b1ab8e246ac9b1957c52a3`; keep draft PR #9 **unmerged, untagged, unpublished**. This document records improvements **beyond** the completed 167-task program. Do not retroactively redefine 167 accepted tasks as proof of new work, and do not weaken U15/U17 gates.

## Priority and decision matrix

| ID | Scope | Decision | Closure requirement |
| --- | --- | --- | --- |
| H01 | Truthful versioned feature catalog, docs and README status | MUST before 3.0 | Remove stale U15/U17 OPEN statements, preserve exclusions and exact source/proof links, GitHub Sphinx green |
| H02 | Automated release feature-claim guard | MUST before 3.0 | Source Audit checks supported/unsupported scope and 167 canonical IDs |
| H03 | Public API and behavior freeze | MUST before 3.0 | Refresh source-linked published 2.1.2 migration diff, reflect changed symbols and test dispositions, no unreviewed public breaks |
| H04 | Adversarial security/lifetime review | MUST before 3.0 | Zero known blocking SSRF, TLS/ALPN, cancellation, child-reaping, cross-tenant pooling, proxy or bounded-memory failures |
| H05 | Unified outbound HTTP entry point and session pooling | PARTIAL IMPLEMENTATION; POOLING OPEN | `HttpOutboundDispatcher` and `OutboundHttpHandle` unify explicit H1/H2/H3 one-request dispatch and cancellation; automatic origin/TLS/tenant-safe H1/H2/H3 pooling, GOAWAY and multi-request session ownership remain OPEN |
| H06 | Optional PSR and Revolt interoperability | PSR-7/15/17 IMPLEMENTED, PSR-18/REVOLT OPEN | `PsrMessageBridge` and bounded host-owned `PsrMiddlewareAdapter` with independent tests; no required PSR production dependency and no blocking PSR streams on native reactor; PSR-18 and Revolt adapters remain unqualified |
| H07 | Observability propagation | W3C + EXPLICIT FIBER IMPLEMENTED, WORKER/SDK OPEN | `Tracing\\TraceContext`, `Tracing\\TraceScope`, bounded W3C headers, child spans, request lifecycle, explicit Fiber context with exception restoration; automatic executor/process propagation and OpenTelemetry SDK hook remain OPEN |
| H08 | DNS cache and in-flight coalescing | IMPLEMENTED; EXACT-HEAD QA PENDING | Opt-in `CachedDnsResolver` and `DnsResolverInterface` injectable into Happy Eyeballs, bounded per-instance positive/negative TTL and pending capacity, cancellation and deadline isolation; real QA underway |
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
| H06–H08 | SUPERSEDED BY H13/H15 SOURCE WORK | Opt-in PSR-7/15/17, W3C Fiber scope and owner-local DNS coalescing were implemented after this historical snapshot. PSR-18/Revolt, automatic process trace propagation, and remaining exact-head QA remain open; see H15. |
| H09 | CODE/TESTS COMPLETE; full QA pending | [`e12d90c`](https://github.com/infocyph/Runwire/commit/e12d90cf79b762a0ec2d43076f821f1c6886191c) cgroup cpuset duplicate/overlap/overflow rejection and fail-safe one-CPU fallback with fractional-quota tests, `tests/SystemResourceProbeCgroupTest.php`; independent 1800s mixed/autoscale and slow-receiver/relay qualification retain their previous scope. |
| H10 | PENDING | Exact last immutable SHA PHPForge/Sphinx/source/portable/host/native plus freshly pinned opt-in 5×180s H2/WS, true 1800s mixed and Release Certification `certification_ready=true`, `open_tasks=0`, `release_tag_created=false`, `package_published=false`. |

**Release governance:** H05–H08 are architecture decisions, not falsely accepted implementations. This freeze covers demonstrable correctness, documentation and security improvements before 3.0. Any high-level API expansion requires a new explicit acceptance program, not reusing U17 proof. PR remains draft. No merge, tag, deployment, release or publication.


## H12 — New implementation batch, test status held open (2026-10-28)

- **H07 partial implementation:** [`4601ac6`](https://github.com/infocyph/Runwire/commit/4601ac6ddeb66da3bbc7d32d0a4b492cb7fe38de) adds strict W3C `Tracing\\TraceContext`, traceparent/tracestate validation, child span creation, immutable header injection, spoofed duplicate rejection, explicit `RequestContext` binding and lifecycle cleanup. No global trace scope, automatic Fiber/executor boundary or OpenTelemetry SDK installation is claimed. New `tests/TraceContextTest.php` and observability manual.
- **H08 implementation:** [`7dcac7c`](https://github.com/infocyph/Runwire/commit/7dcac7c9a315a0b8aa58a8b447e9193f57b2c60f) and [`369b3d4`](https://github.com/infocyph/Runwire/commit/369b3d41b30badf0d4b767b1bb73ffa6df83a911) introduce opt-in `CachedDnsResolver`, `DnsResolverInterface`, Happy Eyeballs wiring, positive and explicit negative TTLs, hostname capacity, independent per-caller deadline/cancellation and last-waiter upstream cancel, with deterministic regression tests. Cache instances are worker/tenant owned, not global. The dialer's SSRF connected-peer recheck remains mandatory. No authoritative upstream DNS TTL claim.
- **P0 test gate repair:** [`8b2b71b`](https://github.com/infocyph/Runwire/commit/8b2b71bdcce56877f7cc2cda0bd3053ecfa4eaaa) restores mandatory measurement-warning language after `PerformanceDocsProvenanceTest` caught stale wording in four PHPForge lanes. This is a real QA repair, not a lowered gate.
- **Remaining beyond this historical snapshot:** complete unified H1/H2/H3 session pooling and safe reuse, PSR-18/Revolt integration (PSR-7/15/17 were subsequently added), automatic trace propagation across executor/worker boundaries, comprehensive new streaming/backpressure and memory-autoscale qualification, final exhaustive cross-version release certification. Those are **OPEN**, regardless of the original 167/167 counter. Do not label H01–H10 COMPLETE or release-ready on these partial changes.


## H13 — Implemented upgrade batches pending latest GitHub QA

**Implementation snapshot source:** `a0c9fb85d29802f9794a7e63093dee8a045ba52d`. These additions are *outside* the original 167 tasks and MUST NOT be called fully accepted until exact-source QA and real end-to-end test evidence pass.

| Upgrade | Source implementation | Test-backed boundary | Remaining |
| --- | --- | --- | --- |
| H06 — PSR integration | [`d7f6ae5`](https://github.com/infocyph/Runwire/commit/d7f6ae5cf81e80d9933b78b78d5280d513913a20) PSR-7/17 header/bodyless metadata bridge; [`a0c9fb8`](https://github.com/infocyph/Runwire/commit/a0c9fb85d29802f9794a7e63093dee8a045ba52d) bounded host-owned PSR-15 middleware adapter; optional composer packages | `tests/PsrMessageBridgeTest.php`, `tests/PsrMiddlewareAdapterTest.php`; never silently invoke blocking PSR streams on reactor | PSR-18 synchronous client intentionally not claimed; native Revolt adapter not yet implemented; full PHPForge QA pending |
| H07 — tracing | [`4601ac6`](https://github.com/infocyph/Runwire/commit/4601ac6ddeb66da3bbc7d32d0a4b492cb7fe38de) W3C trace carrier and [`aca8b12`](https://github.com/infocyph/Runwire/commit/aca8b126f5cff8ed958b979bbd12c86779437b4b) explicit per-worker Fiber trace scopes | `tests/TraceContextTest.php`, `tests/TraceScopeTest.php`; no static global/tenant leak; [`4e46a70`](https://github.com/infocyph/Runwire/commit/4e46a7098e44d3e475ab35f1e38456bbb000b5e4) fixes PHPForge type/regex findings | Automatic executor/worker propagation and optional OpenTelemetry SDK bridge not qualified |
| H08 — DNS | [`7dcac7c`](https://github.com/infocyph/Runwire/commit/7dcac7c9a315a0b8aa58a8b447e9193f57b2c60f) opt-in bounded DNS TTL/negative cache, per-host fanout via `DnsResolverInterface`, injectable into Happy Eyeballs | `tests/CachedDnsResolverTest.php`; per-waiter deadline and cancellation; last waiter retires upstream; [`4e46a70`](https://github.com/infocyph/Runwire/commit/4e46a7098e44d3e475ab35f1e38456bbb000b5e4) splits complexity >12 methods without waivers | PHPForge two-PHP green; measure DNS load amplification and SSRF behavior with cached answers |
| H05 — unified HTTP session manager | H1/H2/H3 protocol-specific verified clients and bounded connection pool already exist. API is NOT a qualified automatically pooled, cross-protocol session manager | Existing `ClientRequest`, `ClientExchangeInterface`, H2 multiplex and H3 sibling contracts | Unified origin/TLS/tenant policy, GOAWAY, reuse, cancellation, protocol selection and matched sustained performance require implementation and independent test; **OPEN**, not automatically closed |
| H09 — streaming / resource autoscale | Existing `ScopedFileTransfer`, `StreamRelay`, H1 streaming, `Supervisor::autoscale`, cgroup hardening [`e12d90c`](https://github.com/infocyph/Runwire/commit/e12d90cf79b762a0ec2d43076f821f1c6886191c) | SHA256 1MiB slow-reader relay, 512KiB pressured transfer with heartbeat, HTTP1 pressure and live worker scale-in/out test suites | Repeat exact-head full 1800s mixed PHP8.4/8.5 source-pinned qualification |

**Safety:** Source audit and individual green workflow checks never substitute for the final all-platform/security/matched-and-long-soak release certification; no merge/tag/publish. Do not rebrand deliberate incompatibilities (PSR-18 sync, WebTransport, native kernel AIO, inner TLS over HTTPS proxy) as features.


## H14 — Unified outbound entry point (not automatically pooled)

[Commit `bd92270`](https://github.com/infocyph/Runwire/commit/bd922706228aac680726fa29829397eff4072c61) introduces `HttpOutboundDispatcher` with `OutboundHttpHandle`, a common callback and cancellation contract for verified H1/H2 and native H3. It intentionally refuses mixed TCP/QUIC ALPN fallback and closes abandoned H1 sockets rather than unverified implicit reuse. The live H1 independent TCP regression is `tests/HttpOutboundDispatcherTest.php`; H3 requires explicit QUIC peer/ALPN. **Not the originally requested automatically pooled all-version HTTP session manager**: that remains open until admission, GOAWAY, per-stream cancellation, tenant identity and reuse tests are source-pinned green.

**Updated H06:** [`a0c9fb8`](https://github.com/infocyph/Runwire/commit/a0c9fb85d29802f9794a7e63093dee8a045ba52d) added bounded PSR-15 host middleware to PSR-7/17 metadata adapters; PSR-18 synchronous client and native Revolt adapter remain open. Do not claim those interfaces are ready or block Runwire's reactor with middleware.

**Current QA rule:** Every new implementation change must pass PHPForge strict cognitive complexity <=12/method, public-migration Source Audit, Sphinx, QA PHP8.4+8.5 and independent protocol/soak release certification. The latest head has not yet passed the entire matrix. No merge, tag or release.


## H15 — Latest release-hardening truth table (2026-10-28)

**Prior implementation checkpoint:** [`3765739`](https://github.com/infocyph/Runwire/commit/37657398e5688d85172fee6a6f9ad39fdeea75b5). **The final source is the current PR head, not this historical checkpoint.** Earlier H11–H14 tables are dated implementation snapshots; **this table supersedes their status rows**, not the original 167/167 accepted U work. The source tree will need a further documentation-only commit for this reconciliation. The final exact revision MUST be requalified before freezing.

| Item | Current disposition | Remaining evidence / implementation |
| --- | --- | --- |
| H01 | IMPLEMENTED | Source-linked supported/experimental/unsupported capability docs; strict Sphinx release-source green required |
| H02 | IMPLEMENTED | Feature-claim fail-closed Source Audit; must remain green |
| H03 | IMPLEMENTED MIGRATION LEDGER | 2.1.2 source / changed-behavior reconciliation; freeze exact final SHA and verify all new public APIs under the migration guard |
| H04 | TARGETED HARDENING IMPLEMENTED | Canonical IPv4-mapped IPv6 connected-peer validation, fail-closed DNS/cgroup limits, bounded worker/cancellation contract; PHPForge and lifecycle QA required |
| H05 | **PARTIAL — SESSION POOLING OPEN** | `HttpOutboundDispatcher` provides one H1/H2/H3 policy and cancellation contract. Cross-version, origin/tenant/TLS-scoped pooled reuse with GOAWAY and admission is NOT implemented; do not claim it |
| H06 | **PSR-7/15/17 IMPLEMENTED; PSR-18/REVOLT OPEN** | Optional `PsrMessageBridge` and host-only `PsrMiddlewareAdapter`, with Nyholm integration tests; synchronous PSR body reads must never run on reactor |
| H07 | **W3C/FIBER IMPLEMENTED; PROCESS/SDK OPEN** | `TraceContext` and `TraceScope`; explicit executor and worker propagation or optional OpenTelemetry SDK bridging not qualified |
| H08 | IMPLEMENTED; QA ACCEPTANCE OPEN | Bounded owner-local positive/negative DNS TTL, dedup and independently cancellable waiters, per-peer SSRF recheck; PHPForge matrix and real client stress evidence remain required |
| H09 | TESTED PRIMITIVES; FINAL FULL-SOAK OPEN | Existing real 1MiB stalled-relay hash, 512KiB file/socket backpressure heartbeat, live autoscale and fractional CPU tests; final 1800s mixed PHP8.4/8.5 evidence required |
| H10 | **OPEN** | Exact final SHA: all Security & Standards, docs, Source Audit, hosted, portable, H2/WS 5×180s, mixed 1800s, release certification green and `open_tasks=0`; no merge, no tag, no publish |

**Release gate remains CLOSED.** No roadmap work is marked complete solely because a draft source commit exists or short tests pass. H05 and the remaining H06/H07 contracts require explicit implementation or an explicitly approved scope change before saying all ten requested improvements are done.


## H16 — Follow-up rolling batches: PSR audit and H1 session pooling (2026-10-28)

**Tracked implementation source** [`6e82f42`](https://github.com/infocyph/Runwire/commit/6e82f4215a370d7c4a3a295231455cc91aeae46d) through [`2f97938`](https://github.com/infocyph/Runwire/commit/2f9793801e3aea791334992a147c85dabab0c2ea). **Final code SHA is the current branch head, not these historical checkpoints.**

- **PSR-15 QA blocker fixed:** PHPForge's four QA jobs on earlier `89d990e` failed only `PublicApiDocumentationTest`: a public type had no source-level docblock. Initial repair introduced a second type in one PHP source file, which Source Audit correctly rejected. [`6e82f42`](https://github.com/infocyph/Runwire/commit/6e82f4215a370d7c4a3a295231455cc91aeae46d) gives `PsrMiddlewareHandlerLayer` its own source file and full type/method docblocks. Exact-head Source Audit #40679099657, Sphinx #40679099835 and independent references passed on that revision; the final PHPForge QA and whole-program gates still require latest-head evidence.
- **H05 H1 pooling implemented but H2/H3 pool unqualified:** [`8371137`](https://github.com/infocyph/Runwire/commit/8371137dab840820db5d7a271c62fea824fd1ffe), [`7f89698`](https://github.com/infocyph/Runwire/commit/7f8969851006d7c145d67e60c56f34bf7938b5f9), [`73de88e`](https://github.com/infocyph/Runwire/commit/73de88eb5e465343ad3b7a17d87042f167211736) and [`2f97938`](https://github.com/infocyph/Runwire/commit/2f9793801e3aea791334992a147c85dabab0c2ea) add `PooledHttp1Session`: fixed origin, tenant/worker scope and revisioned HTTPS trust identity, strict H1-only ALPN, bounded `BoundedConnectionPool`, safe reuse only after real parser completion and idle safety, prompt queued-waiter cancellation, cleanup on failed admission/shutdown, live two-request same-socket regression, and real reused-socket counters. `HttpOutboundDispatcher` continues selecting H1/H2/H3 without hidden downgrade. This is **not automatically pooled H2 multiplex or native H3 session reuse**.
- **Open scope:** H05 complete automatically pooled H1/H2/H3 origin/tenant/TLS session manager (H1 implemented, H2/H3 integration and GOAWAY/reuse unqualified), optional PSR-18/Revolt integration where safe, automatic interprocess trace propagation, exact-head green PHPForge and repeat five-pair H2/WS/1800s mixed release gates. None may be marked accepted without genuine green evidence. Native Swoole/QUIC and packaging must remain covered. **No merge / tag / publish.**


## H17 — Shared H1/H2/H3 dispatcher and release gate (2026-10-28)

**Implementation revision:** [`344cb70`](https://github.com/infocyph/Runwire/commit/344cb70a845eaed4fbd029ba3cf9693adaeb1f6f) (historical until the final documentation/QA SHA).

- **H05: explicit cohesive session reuse implemented; automatic H2/H3 pool discovery and establishment still OPEN.** `HttpOutboundDispatcher` accepts optional `PooledHttp1Session`, already-TLS-verified `Http2MultiplexConnection` and caller-established `Http3OutboundClient` sibling session. The common request cancellation owner now dispatches H1 keepalive, H2 bounded per-stream multiplex or H3 sibling streams without hidden transport fallback; the respective original-origin and TLS/ALPN checks stay in the verified protocol owners. No unverified socket is carried across an origin or tenant boundary. The H1 pool's real reused socket test and cancellation tests are in `tests/PooledHttp1SessionTest.php`; policy override checks in `tests/HttpOutboundDispatcherTest.php`. Automatic creation and pooling of H2/H3 sessions by origin is **not** implemented or release-qualified; do not advertise it.
- **H06: optional PSR bridge implemented for PSR-7/15/17, not a synchronous native PSR-18 adapter.** Host-owned PSR-15 processing remains bounded and must not execute blocking PSR stream calls on the event-loop thread. Revolt and PSR-18 remain separately scoped integration possibilities, not release-qualified capabilities.
- **QA history:** [PHPForge #40677392777](https://github.com/infocyph/Runwire/actions/runs/40677392777) original red (one PSR anonymous class documentation failure in four PHP lanes), then [`6e82f42`](https://github.com/infocyph/Runwire/commit/6e82f4215a370d7c4a3a295231455cc91aeae46d) moves the PSR handler class to its own documented type file. Later source audits have passed, but the final changed production SHA is **not** all-green until its own full PHPForge quality, exact source public migration audit, H2/WS five matched 180s, independent 1800s mixed PHP8.4/8.5 and final Release Certification all pass. **No merge, tag, package publication or release is authorized.**
