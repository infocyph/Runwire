# Runwire 3.0 — consolidated unified-runtime roadmap

**Decision (2026-10-23):** The next planned stable release is **3.0.0**, compared directly to published **2.1.2** at commit 7e9bf20ad0737a187dfed77b723791b141b36378. **Do not release 2.2.0.** Preserve the source work and evidence produced under its unfinished development plan.
**Work location:** infocyph/Runwire only. **Stay on the existing plan/runwire-independent-ecosystem-roadmaps branch and draft PR #9.** No new branch or changes to Foundation, Infbyte, Runwire-Core, Runwire-Cells or other repositories.
**Implementation:** **U00 COMPLETE (10/10), U01 COMPLETE (9/9), U02 COMPLETE (9/9), U03 COMPLETE (10/10); U04 7/8; U05 7/10; U06 COMPLETE (8/8); U07 2/11; U08 6/10; U09 3/10; U10 1/9; U11 6/10; U12 3/9; U13 8/9; U14 2/8; U16 9/16**. **100/175 accepted tasks, 75 open across 18 batches** (57.1%). The unified established-stream factory, off-loop DNS and verified outbound TCP/TLS dialing are implemented; full 3.0 transport and QUIC certification remain open. The [detailed 3.0 plan and canonical task tracker](runwire-3.0-unified-runtime-development-plan.md) is the one progress source of truth.

## Release definition

Deliver one **complete, bidirectional asynchronous network and I/O runtime for PHP**, not separate inbound/server and outbound/client stacks. Breaking public API changes are permitted when they make ownership, lifecycle, cancellation or transport behavior coherent; each change must have explicit 2.1.2-to-3.0 migration instructions. **Do not leave the agreed feature set for 3.1**.

| Pillar | Required scope for Runwire 3.0.0 |
| --- | --- |
| Unified transport | TCP, TLS, Unix streams, UDP and QUIC: listening and outbound clients; IPv4/IPv6, nonblocking DNS, Happy Eyeballs, per-scope resources and limits |
| Unified protocol execution | HTTP/1.1, HTTP/2, HTTP/3 clients and servers; WebSocket clients and servers; WebTransport clients and servers |
| Networking services | Secure client TLS/mTLS, SNI/ALPN, pooling, HTTP CONNECT, SOCKS5, stream relay and half-close |
| Unified concurrency | Select/ext-event/Swoole readiness, Fibers, Future, cancellation, inherited deadlines, typed errors and structured task scopes |
| Unified asynchronous I/O | Genuine off-event-loop filesystem and DNS operations, bounded executor, file/network transfer with backpressure |
| Unified runtime | Native prefork/portable, Swoole/OpenSwoole, FPM, FrankenPHP, RoadRunner, crash recovery, graceful rolling reload, native adaptive worker autoscaling |
| Security and performance | SSRF/rebinding policy, TLS validation, fuzzing, concurrency limits, tracing, p99/CPU/RSS/FD, independent protocol interoperability and frozen-source release gates |
| Complete published documentation | **Read the Docs-compatible Sphinx** with full feature catalog, complete PHP API reference, client/server tutorials, migration, capabilities/limitations, operational guides and strict docs CI |

## Existing 2.2 work: consolidated rather than discarded

The [2.2 historical tracker in immutable Git history](https://github.com/infocyph/Runwire/blob/7afd11e21ec4e64c610885f5f6ba42311b61c4d0/docs/plans/runwire-2.2-runtime-improvement-plan.md) has **95 original tasks: 75 previously checked, 20 unfinished**. Those previous 75 items provide existing code, regression fixtures, benchmark infrastructure and evidence, **not 75 newly completed 3.0 tasks**.

- **Retain and requalify:** event-loop timer fairness, ByteQueue batching, host request normalization, worker generation/cancellation isolation, H1/H2/H3 hardening, QUIC peer-reset/QPACK fixes, real host acceptance and resource telemetry.
- **Consolidate unfinished:** 13 previously active performance, protocol, lifecycle and release-qualification tasks; seven dialer items R5.02–R5.08 previously deferred under 2.2. **All 20 are now required** and individually mapped into 3.0.
- **Build once:** one transport connection lifecycle and resource owner; reuse established stream machinery for both accepted and dialed connections; retain protocol-specific and transport-specific state as needed. No duplicate old and new implementations retained solely for backward compatibility.
- **Rebaseline:** architecture changes invalidate automatic certification; PHP8.4/8.5 real-client and host QA, package smoke, benchmarks and soak must all be repeated on the new 3.0 candidate.

### Exact 20-task crosswalk

| Old unfinished task | 3.0 canonical task(s) | Status |
| --- | --- | --- |
| R0.06 | U15.01 | OPEN, fresh 3.0 QA required |
| R0.07 | U15.02 | OPEN, fresh 3.0 QA required |
| R1.08 | U15.03 | OPEN, fresh 3.0 QA required |
| R3.09 | U08.09 + U15.04 | OPEN, fresh 3.0 QA required |
| R4.02 | U08.04 + U15.04 | OPEN, fresh 3.0 QA required |
| R4.04 | U08.05 + U15.04 | OPEN, fresh 3.0 QA required |
| R4.09 | U08.08 + U15.05 | OPEN, fresh 3.0 QA required |
| R5.02 | U03.01 | ACCEPTED; U03.01 verified in QA #39929786108 |
| R5.03 | U02.06 + U06.03 | ACCEPTED; U02.06 QA #39926291446; U06.03 PHPForge QA #40050545361 and Swoole #40050545190 |
| R5.04 | U02.04 | ACCEPTED; U02.04 verified in QA #39923215737 |
| R5.05 | U04.02 + U04.06 | ACCEPTED; both tasks passed QA #39995711371 |
| R5.06 | U03.08 | ACCEPTED in 3.0 U03.08; QA #39975849650 |
| R5.07 | U03.09 + U13.03 | ACCEPTED; U03.09 #39976909971 and U13.03 live Swoole/OpenSwoole reactor plus outbound dial #40064041118 |
| R5.08 | U03.10 + U15.06 | PARTIAL; U03.10 accepted in QA #39977854249, U15.06 OPEN |
| R6.02 | U12.05 | OPEN, fresh 3.0 QA required |
| R8.05 | U17.06 | OPEN, fresh 3.0 QA required |
| R8.10 | U17.07 | OPEN, fresh 3.0 QA required |
| R9.06 | U17.08 | OPEN, fresh 3.0 QA required |
| R9.07 | U17.07 | OPEN, fresh 3.0 QA required |
| R9.09 | U17.09 | OPEN, fresh 3.0 QA required |

## Delivery program and initial tracker

| Batch | Division | Open acceptance tasks | Progress |
| --- | --- | ---: | --- |
| U00 | Baseline, architecture and 2.1-to-3.0 migration | 0 | 10/10 |
| U01 | Event-loop, watchers and asynchronous executor | 0 | 9/9 |
| U02 | Unified established connection and transport ownership | 0 | 9/9 |
| U03 | Async DNS and outbound TCP/Unix dialer | 0 | 10/10 |
| U04 | Unified TLS security and client/server handshakes | 1 | 7/8 |
| U05 | UDP, outbound QUIC and unified datagram transport | 3 | 7/10 |
| U06 | Structured concurrency, tasks, deadlines and scopes | 0 | 8/8 |
| U07 | HTTP/1.1, HTTP/2 and HTTP/3 clients | 9 | 2/11 |
| U08 | Existing H1/H2/H3 server consolidation | 4 | 6/10 |
| U09 | WebSocket and WebTransport client/server | 7 | 3/10 |
| U10 | Connection pooling, proxy negotiation and stream relay | 8 | 1/9 |
| U11 | Truly asynchronous filesystem and file streaming | 4 | 6/10 |
| U12 | Native supervisor and adaptive worker autoscaling | 6 | 3/9 |
| U13 | Real host and optional extension compatibility | 1 | 8/9 |
| U14 | Security, fuzzing and adversarial resilience | 6 | 2/8 |
| U15 | Performance attribution, regressions and soak | 10 | 0/10 |
| U16 | Read the Docs-compatible Sphinx documentation and full feature catalog | 7 | 9/16 |
| U17 | Final certification, risk signoff and maintainer handoff | 9 | 0/9 |
| **TOTAL** | **Full unified-runtime release program** | **75** | **100/175 (57.1%)** |

**Work order:** U00 source/API baseline and migration → U01 event loop/executor → U02 established transport → U03 asynchronous dialer/DNS → U04 TLS → U05 UDP/QUIC → U06 scopes/concurrency → U07/U08 clients and server protocols → U09 WebSocket/WebTransport → U10 pooling/proxy/relay → U11 async filesystem → U12 native autoscaling → U13 hosts → U14 security → U15 performance → U16 Sphinx/Read the Docs → U17 immutable release certification. Documentation must be written alongside implementation; U16 is the full documentation acceptance gate.

## Read the Docs and Sphinx: first-class 3.0 release requirement

**Do not defer documentation or ship a partial feature list.** Implement a maintained documentation site using Sphinx using only native reStructuredText (.rst), a pinned documentation environment, docs/conf.py, repository-root .readthedocs.yaml **configuration version 2**, structured toctree, readable theme, search and versioned migration content.

**Required full feature catalog:** for every user-facing 3.0 capability record its category, public PHP API, client/server applicability, optional extension/OS/runtime requirements, timeouts, cancellation and resource ownership, defaults, security considerations, errors and real tested examples. Cross-link every entry to the generated public PHP API reference. Include the old 2.1.2 behavior, all intentional breaking changes and before/after migration snippets.

**Required sections:** getting started, installation, feature catalog, API, TCP/TLS/Unix/UDP/QUIC, DNS/dialer/racing, H1/H2/H3 client-server, WebSocket/WebTransport, pooling/HTTP CONNECT/SOCKS5/relay, Fiber/scheduler/scopes, async filesystem, worker lifecycle/autoscaling, host capabilities, production deployment, security, profiling/metrics/performance, troubleshooting, recipes and release QA.

**RST-only policy:** Publish only `.rst` pages through Sphinx. Convert existing user-facing `.md` guides before release; never load internal `.md` plans/evidence into Sphinx. No MyST parser. Follow InterMix's `docs/conf.py`, `docs/index.rst` and `.readthedocs.yaml` convention.

**Docs acceptance:** warning-as-error and strict-reference Sphinx HTML build with --keep-going, local snippet tests, stable internal/external links, Read the Docs preview deployment and 100% public feature documentation coverage. Documentation tasks U16.01–U16.16 and final docs signoff U17.04 are mandatory.

## Acceptance and immovable quality boundaries

- **True async:** DNS and filesystem must execute off the event-loop thread through a bounded executor or equivalent nonblocking facility. No silent synchronous blocking fallback.
- **Portable package:** PHP 8.4+ 64-bit and Composer; optional ext-event, ext-sockets, ext-openssl, ext-quic, PCNTL/POSIX and host extensions remain optional capability gates, not unconditional dependencies.
- **Hosted semantics:** Swoole/OpenSwoole reactor ownership; native portable/fork modes; genuine RoadRunner/FrankenPHP/FPM requests. Native autoscaling may not falsely claim it can control host-owned worker pools.
- **Protocol QA:** real independent client and server peers for H1/H2/H3, WebSocket, WebTransport, TCP, UDP and QUIC; malformed streams, peer resets, cancellations, slow receivers, TLS and SSRF policies and overload limits.
- **Performance QA:** compare exact published 2.1.2 with a frozen 3.0 SHA, five paired 180-second warmed successful-RPS trials, **both populations CV below 2.5%**, strict correctness/p99/RSS limits, generator headroom and a real **1800-second process-tree/worker-GC/OPcache/FD soak**.
- **Per-batch discipline:** design → implementation → targeted QA → full relevant PHPForge/real-peer CI → evidence links → checkbox, batch row and PR synchronization. A plan description alone proves nothing.

## Historical certification disclosure

The seven regular standard 2.2 workflows passed on source 8b125bab00b89af669234b883d18561d0d00a1c1; the separate [Release Certification #39891900634](https://github.com/infocyph/Runwire/actions/runs/39891900634) on that source **FAILED overall** because paired benchmark variance exceeded 2.5%. Its monitored 30-minute soak and other four jobs passed individually. Do not describe the old attempt as final certification. Earlier passing H1-only measurements were on older source commits and do not constitute 3.0 qualification.

## First U00 implementation and QA acceptance (2026-10-23)

- U00.01 accepted: published `2.1.2` **350-source-type public signature manifest** generated with the source-only PHP exporter, sha256 `a2348788547bb8e3c89a49db399862afc6a13f18fb0aa5470d27f89780871dd1`; committed [manifest lock](evidence/runwire-3.0/public-api-2.1.2.lock.json), [source audit](https://github.com/infocyph/Runwire/actions/runs/39904006992) and [full manifest artifact](https://github.com/infocyph/Runwire/actions/runs/39904006992/artifacts/12479892848). Source Audit regenerates and checks the permanent lock every run.
- U00.02/.03 accepted: all historical 75 checked groups classified by old batch, all 20 unfinished IDs mapped with automated tests; original deleted 2.2 plan remains in immutable Git history.
- U00.06/.07 accepted as **design specifications**, not implemented API: [breaking migration](evidence/runwire-3.0/u00-public-api-migration-draft.md), [transport error/ownership/deadline contract](evidence/runwire-3.0/u00-resource-state-contract.md).
- U00.08/.10 accepted: [exact current native/host capability inventory](evidence/runwire-3.0/u00-architecture-baseline.md) with [real-host QA](https://github.com/infocyph/Runwire/actions/runs/39904006975) and maintainer-authorized release scope/branch in draft PR #9.
- Relevant PHPForge QA: **all four PHP8.4/8.5 stable/lowest QA jobs and both PHPStan/Psalm analysis jobs succeeded** in [Security & Standards #39904007423](https://github.com/infocyph/Runwire/actions/runs/39904007423). That statement covers the finished PHPForge jobs, not the whole workflow while long HTTP/3 jobs run.
- **U00 COMPLETE 10/10:** [RST pending-dial and factory decision](evidence/runwire-3.0/u00-dial-attempt-decision.rst) plus executable 64-pair transitions, [two PHP runner hardware/affinity inventory artifacts](https://github.com/infocyph/Runwire/actions/runs/39907036881), and PHP8.4/8.5 QA. This accepts **design and baseline**, not a factory/dialer implementation; U02/U03/U15 remain open. U01 is next. **3.0 remains NOT READY.**

## Governance

Only Runwire code/docs/QA. The obsolete 2.2 plan and unpublished release notes were removed from the working tree; preserve their immutable Git history and historical QA artifacts under [evidence/runwire-2.2](evidence/runwire-2.2/README.md). Never erase failing CI evidence. This branch and PR stay in place, **draft/unmerged**. No tag, release or publish. The canonical task tracker reaches **READY FOR MAINTAINER RELEASE** only after every 3.0 task, security, QA, documentation and exact-source certification gate is verified.

**U01 completion (2026-10-23):** 9/9 accepted; [source commit a5a900b](https://github.com/infocyph/Runwire/commit/a5a900b701ef67035218402249ec66a689e4d520), [full U01 evidence](evidence/runwire-3.0/u01-executor-qa-closeout.rst), [PHPForge QA/analysis #39913487501](https://github.com/infocyph/Runwire/actions/runs/39913487501) all four PHP8.4/8.5 stable/lowest suites and both analyzers PASS, [core benchmark jobs #39913487092](https://github.com/infocyph/Runwire/actions/runs/39913487092) PHP8.4/8.5 PASS with CPU, memory, p99 and real subprocess fairness. U02 is next. Extended H3 and adaptive H2 workflow conclusions require separate review before declaring *all* standard CI green.

**U02.01 verified starting implementation:** One `Network\\Connection` instance is produced for accepted TCP/Unix or verified dialed streams through `ConnectionFactory`; illegal/double handoff rejected, real socket regression tested. [Source](https://github.com/infocyph/Runwire/commit/8ab16398e6de6ff211cc5b03d9dbbcf3ab8f8b43), [PHPForge QA/analysis](https://github.com/infocyph/Runwire/actions/runs/39914604756) and [PHP8.4/8.5 core benchmarks](https://github.com/infocyph/Runwire/actions/runs/39914604214) passed relevant jobs. U02.06/.07/.09 accepted; U03 is next; do not infer whole-workflow PASS while extended jobs run.

**U02.02 accepted:** [metadata normalization](https://github.com/infocyph/Runwire/commit/4cc06dda9eddc03cde57a715cd311d355ba80d98) now centralizes real TCP/Unix endpoint addresses, observed TLS encryption/ALPN and optional captured peer certificate SHA-256 identity. [PHPForge QA and both analyzers](https://github.com/infocyph/Runwire/actions/runs/39921145750) PASS for PHP8.4/8.5 stable/lowest. Remaining U02.03–U02.09 are open. The extended OpenSwoole PHP8.4 multiworker test failed on one fixture response; investigate independently, do not assert full workflow green.

**U02.03/.04/.05/.08 accepted:** [lifecycle/handoff source 1ac186f](https://github.com/infocyph/Runwire/commit/1ac186f6b2b14c2dfbf1c70290c7509750d5ea68), real local half-close and no-duplicate callback transfer, existing bounded shared buffering plus framing/accepted hot-path regression tests. [All PHP8.4/8.5 QA/analyzer lanes #39923215737](https://github.com/infocyph/Runwire/actions/runs/39923215737) and [core benchmark jobs #39923214991](https://github.com/infocyph/Runwire/actions/runs/39923214991) PASS. U02.06 cancellation scope, U02.07 explicit socket policy and U02.09 churn/FD stress remain open.

**U02 closeout:** 9/9 accepted at source [a356384](https://github.com/infocyph/Runwire/commit/a35638472361995396980e6aa1065326bed92ccd). [Four PHPForge QA lanes and two analyzers](https://github.com/infocyph/Runwire/actions/runs/39926291446) passed; new tests cover owner cancellation/deadlines, native TCP_NODELAY policy, 128 concurrent stream pairs and shutdown of a 24-connection TCP listener. Extended H3 and adaptive H2 workflow tails were still running when evidence was written; do not claim whole-workflow success before conclusion. **U03 in progress: 3/10 accepted for typed endpoints, numeric TCP and Unix dialing; DNS and Happy Eyeballs remain open.**

**U03 first accepted slice:** typed `DialEndpoint` and `DialOptions`, nonblocking numeric TCP writable/SO_ERROR verification, and Unix-domain connects are tested against real peers with missing-socket and cancellation cleanup. [Source 50fe962](https://github.com/infocyph/Runwire/commit/50fe962257b2de6050b22a6f004dfb41faaf4e69), [four QA lanes and both analyzers #39929786108](https://github.com/infocyph/Runwire/actions/runs/39929786108) passed. IPv6 live loopback test is conditional on host IPv6 capability. DNS, address racing, full scope/worker integration and benchmark claims await U03.04–U03.10.

**U03.04 accepted — bounded off-loop DNS:** `AsyncDnsResolver` uses Runwire's existing persistent PHP subprocess executor for A/AAAA, hosts-file IPv4 fallback, deduplicated/validated addresses and exact-once cancellation. `AsyncDnsResolverTest` proves a 3ms event-loop heartbeat continues while a 60ms blocking-worker fixture queues the DNS result. [Source 3232cfd](https://github.com/infocyph/Runwire/commit/3232cfde3683d16849381a1085d141e5cd1561e5); [PHPForge QA and both analyzers #39967719050](https://github.com/infocyph/Runwire/actions/runs/39967719050) passed. **U03.05–U03.08 accepted:** hostname dial and staggered address fallback are implemented and verified; U03.09/10 remain open pending hosted compatibility and benchmark evidence.

**U03.05–U03.08 accepted:** [source 3942b0e](https://github.com/infocyph/Runwire/commit/3942b0e276cce6883689a88a29a98482aa8e42b7) implements staggered IPv6/IPv4 fallback, one overall monotonic deadline across DNS/attempts, bounded scope/worker/socket queues and the same callback/Fiber `AsyncDialer` transport. [All four PHPForge QA lanes and both analyzers #39975849650](https://github.com/infocyph/Runwire/actions/runs/39975849650) PASS. Hosted Swoole/OpenSwoole family verification and recorded sequential/overlapped dial benchmarks are implemented in the next code slice but **not accepted** until the new workflow evidence is green.

**U03.09 hosted reactor ACCEPTED:** actual localhost DNS + outbound Fiber dial through the host-owned Swoole loop is now exercised in the existing [Swoole/OpenSwoole workflow #39976909971](https://github.com/infocyph/Runwire/actions/runs/39976909971). Four independent extension × PHP version acceptances passed (Swoole/OpenSwoole × 8.4/8.5). U03.10 sequential/overlap benchmark remains open pending strict QA and metric archive.

**U03 COMPLETED (10/10) / U04 STARTED (3/8):** verified outbound IPv4/IPv6/Unix and off-loop DNS + Happy Eyeballs, full Fiber integration and [real sequential/overlapped dial benchmark on PHP8.4/8.5 #39977854249](https://github.com/infocyph/Runwire/actions/runs/39977854249) accepted. Secure client-side TLS policy, nonblocking TLS1.2/1.3 and SNI/ALPN/hostname+trust verification run through existing shared connector with real success/failure/timeout tests on PHP 8.4/8.5 stable/lowest and both analyzers [#39982999768](https://github.com/infocyph/Runwire/actions/runs/39982999768). U04.03/05/06/07/08 remain open; whole 3.0 performance certification and Sphinx feature catalog remain scheduled separately.

**U04 5/8 accepted:** dedicated client/server mutual TLS verification with positive and missing-identity rejection, plus portable explicit no-OpenSSL fail-closed semantics; [four PHPForge QA and two analyzers #39989750473](https://github.com/infocyph/Runwire/actions/runs/39989750473) and [portable native #39989750415](https://github.com/infocyph/Runwire/actions/runs/39989750415) PASS. U04.03 shared state, U04.06 TLS close/truncation semantics and U04.08 matched TLS performance remain open. **U05 initial outbound UDP client code has been added for later QA; U05 acceptance remains 0/10 until dependencies and evidence are met.**

**U04 6/8, U05 3/10 accepted at [#39992267462](https://github.com/infocyph/Runwire/actions/runs/39992267462):** `TlsHandshakeReadiness` now provides a shared inbound/outbound TLS timer/read-write watcher owner, preserving real ALPN and mTLS tests; client UDP delegates to the *same existing datagram engine* and passes real IPv4/IPv6, connected/unconnected, timeout, empty/oversize and truncation tests. [Source 20e7f42](https://github.com/infocyph/Runwire/commit/20e7f42ffa95e63b02e93c84bcea0f43dfc81af3). **Outstanding:** U04.06 close-notify/truncation, U04.08 baseline TLS benchmarks, U05.04–U05.10 real QUIC protocol/security/performance; no 3.0 release claims.

**U04.06 accepted, U04 now 7/8:** outgoing verified TLS closes with an explicit fail-closed `TLS_UNVERIFIED_EOF` rather than trusting peer EOF, and encrypted TCP half-close is prohibited. Real TLS timeout/mTLS and synthetic EOF exact-once watcher-cleanup tests passed [all PHPForge and analyzer lanes #39995711371](https://github.com/infocyph/Runwire/actions/runs/39995711371). PHP cannot directly expose authenticated close_notify: clean peer EOF may be rejected, as documented in `evidence/runwire-3.0/u04-tls-eof-policy.rst`. New **opt-in** matched TLS certification tooling requires full 2.1.2-versus-3.0 measurements before U04.08 acceptance; U04.08 remains OPEN. `QuicClientOptions` rejects unsupported ext-quic datagrams/migration/0-RTT and is preparatory until client transport QA; U05 remains 3/10 accepted.

**QA exception (2026-10-24):** U06.06/U06.07 accepted against task-scoped [PHPForge #40053268784](https://github.com/infocyph/Runwire/actions/runs/40053268784) and [Swoole #40053268453](https://github.com/infocyph/Runwire/actions/runs/40053268453). Extended [H1/H2 Benchmarks #40053268411](https://github.com/infocyph/Runwire/actions/runs/40053268411) remains **FAILED** in HTTP/1.1 sustained phase; this is an independent hard gate for U15/U17 and has not been marked passed or waived. U06.02 and U06.08 remain open.

**U06 closed, 2026-10-24:** U06.02/U06.08 accepted by the exact-head [PHPForge QA #40055732464](https://github.com/infocyph/Runwire/actions/runs/40055732464) and [Swoole/OpenSwoole #40055732298](https://github.com/infocyph/Runwire/actions/runs/40055732298). This is coroutine correctness and resource endurance acceptance, not matched production throughput promotion. The opt-in U04 TLS and U15/U17 benchmark release gates remain OPEN; independent extended H1/H2 run #40055732279 must be evaluated independently of U06 task counters.

**2026-10-24 remaining TLS/QUIC batched priority:** Q0 CI fix + fail-closed QUIC safety guards → Q1 mandatory U04.08 matched TLS trial → Q2 off-loop or genuinely asynchronous QUIC client + streams → Q3 real-session datagrams/migration/0-RTT negative proofs → Q4 adversity and QUIC/H3 benchmark certification. Q1 requires dispatch of an existing opt-in workflow and Q2 requires solving the blocking native handshake; neither is waived. Counts remain **56/175**, U04 **7/8**, U05 **3/10** until corresponding acceptance evidence passes.

**Q2-C QA feedback and next grouped batch (2026-10-24):** Recover PHPStan worker-native `close()` typing and PHPCS fixture stdout from QA #40058448441; add verified ALPN + hostname-negative independent QUIC handshake tests, cancellation and queued deadline regressions. Keep U05 3/10 accepted until reusable outbound streams and native independent interoperability meet each U05 task's acceptance. Plan contains the mandatory A push/QA ∥ B implementation → (A QA fixes + B) push workflow. Overall remains 56/175, U04 7/8.

**Q2-D planned independent QUIC stream-worker IPC (2026-10-24):** Strict bounded `QuicWorkerCommand` framing and negative tests are staged, not yet task acceptance. It supplies request IDs, explicit stream commands and size ceilings needed by the next real persistent session worker; no native objects cross the reactor/worker boundary. U05 remains 3/10 until independent-server stream and cancellation QA pass.

**Q2-E persistent QUIC native worker work staged (2026-10-24):** Use a dedicated existing ProcessExecutor one-worker pool to retain TLS-verified native connection and logical stream state across bounded `quic_session` commands. Interface/worker code and unit tests prepared; independent-server bidi/unidi acceptance is still needed. Do not accept U05.04/.05 until live protocol results and exact-head QA pass; U05 remains 3/10, U04 7/8, overall 56/175.

**Q2-E grouped QA repair and native persistence (2026-10-24):** Correct Pest generic-Throwable matcher and Pint class ordering from prior PR run #40059182244 alongside new one-worker native QUIC session lifecycle, dual-bidi stream echo fixture and independent aioquic CI. QA must validate the current commit before considering U05.04/05; U05 remains 3/10 and U04 7/8 pending required evidence.

**Q2-E failure-path tests added (2026-10-24):** Binary stream data/FIN, unidirectional-read protection, per-session stream admission, reset and stale-epoch rejection are staged alongside independent echo tests. Maintain total 56/175; no QUIC milestone is promoted before new PR CI.

**Q2-F cross-stack uni receipt (2026-10-24, QA pending):** Run real independent aioquic receive/FIN of client unidirectional data in addition to same-session multiple bidirectional echo streams; record server receipt, do not accept U05.05 without corresponding full security/host/benchmark evidence. Totals unchanged: 56/175, U05 3/10, U04 7/8.

**Real positive QUIC persistence observation (2026-10-24):** Independent aioquic TLS 1.3 + two reusable worker-owned bidi streams PASS inside Security #40059788005, but that head's PHPForge/PHPStan/Pint/PHPCS FAIL. Grouped Q2-F fixes must pass the full matrix before U05.04/05 acceptance. One-way peer receipt is a separate next test. Totals remain 56/175, U04 7/8, U05 3/10.

**Q2-F stream FIN protection (2026-10-24):** Worker rejects short native writes on FIN and fails closed, with dedicated negative fixture; keep U05.04/.05/.09 QA pending until exact-head native stream transport and adversity pass.

**Q2-G QA recovery + native command replay safety (2026-10-24; new PR QA pending):** Against source `4ff090c`, [Security & Standards #40060404846](https://github.com/infocyph/Runwire/actions/runs/40060404846) failed in the four PHPForge QA lanes (stale Pest exception message and QUIC worker Pint) and both PHPStan lanes (mixed ALPN argument). H3+QUIC, source audit, docs, hosted/portable and ordinary benchmarks succeeded, but that is not overall green. Combine typed validated ALPN, the corrected negative test and worker formatting with one new fail-closed protocol guard: worker-local command IDs must increase within a verified epoch; cancellation may leave gaps, but duplicate/reordered replay invalidates the native connection. Dedicated positive-gap and negative-replay worker tests accompany this source batch. Track full exact-head QA and independent aioquic uni receipt before U05.04/U05.05 acceptance. **Totals unchanged:** 56/175 accepted, 119 OPEN; U04 7/8, U05 3/10, U06 8/8. Mandatory opt-in U04.08 matched TLS, QUIC adversity and release certification remain unaccepted.

**Q2-H overlap + resource reuse and QA recovery (2026-10-24; pending):** Q2-G [QA #40062249460](https://github.com/infocyph/Runwire/actions/runs/40062249460) passed Pest, PHPStan/Psalm, Rector/PHPCS/Deptrac, native H3/QUIC and independent peer streams, hosted integrations, ordinary H1/H2 benchmarks, docs and packaging; four PHPForge lanes remain red **only for Pint formatting of QuicWorkerSession.php**. The grouped code repairs stream-slot exhaustion across a long-lived native QUIC session, frees slots on observed EOF/reset, preserves increasing stream IDs, proves sibling continuity after reset, and requires independent aioquic to echo two **simultaneously opened** bidi streams plus the FIN-confirmed client uni stream before closing the sole worker-owned native connection. A temporary single native-QUIC-job formatter diagnostic prints exact PHPForge Pint transformations for final follow-up, without a redundant standalone workflow or style suppression. Acceptance remains **56/175, 119 open**, U04 7/8, U05 3/10 until matched current SHA QA passes, and full QUIC adversity/TLS benchmarks remain required.

**Q2-I exact Pint fix (2026-10-24; QA pending):** [Q2-H Security & Standards #40063306046](https://github.com/infocyph/Runwire/actions/runs/40063306046) completed both native QUIC jobs, Pest, PHPStan/Psalm, and non-style PHPForge checks successfully; only the four PHPForge QA matrices failed `ordered_class_elements`. The temporary PHP 8.5 formatter diagnostic in that run printed the exact transformation: move the existing `QuicWorkerSession::teardown()` private method to the first private-method slot before the public method (with no semantic changes). Apply that exact change and remove the temporary diagnostic step; run fresh full PR QA once. Benchmarks, Sphinx, hosted/portable, packaging and source-audit workflows at the old Q2-H head passed. U05.04/U05.05 remain QA-gated until the corrected head passes; the opt-in U04.08 TLS certification has not run. **Acceptance remains 56/175, 119 open, 32 additional accepted tasks required for 88/175 (50.3%).**

**Q2-I green → Q2-J verified acceptance + new live negative/reset tests (2026-10-24):** Exact-head [Security & Standards #40064041388](https://github.com/infocyph/Runwire/actions/runs/40064041388), [Sphinx #40064041098](https://github.com/infocyph/Runwire/actions/runs/40064041098) and every other mandatory default PR workflow succeeded for `80120be`. Accept U05.04 (native off-loop verified QUIC+ALPN independent real peer), U07.01 (streaming client interface/version policy, not protocol client implementation), U16.01 (strict pinned Sphinx HTML). **Canonical 59/175 accepted (33.7%), 116 open; 29 more for the 88-task 50.3% milestone.** The new Q2-J fixture tests explicit native datagram/migration/0-RTT unsupported behavior both before connection and after verified handshake, followed by real reset and subsequent peer-observed uni FIN. Q2-J transport guard tasks stay OPEN until current-head acceptance; U04.08 matched TLS, U05 flow/cancel/adversity and U15/U17 performance remain OPEN. Draft PR #9 unchanged; Runwire only.

**F1 staged during Q2-J QA (2026-10-24):** Incremental off-loop file I/O builds on the existing `ProcessExecutor` rather than a second thread/process runtime. New bounded write/stat/read/rename/unlink/cancel facade, binary offset operations, explicit worker cleanup and negative/heartbeat tests are prepared alongside an RST development guide. This does not yet implement a persistent `open` handle, safe sandbox/TOCTOU policy, native disk acceleration, file/network streaming or U11 benchmark certification; no U11 task is checked before exact-head QA. **Accepted 59/175 (33.7%), 116 open; 29 tasks to 50.3%.**

**H1 real hosted acceptance while F1 is staged (2026-10-24):** [Swoole/OpenSwoole #40064041118](https://github.com/infocyph/Runwire/actions/runs/40064041118) passed all four PHP version and extension combinations with live hosted reactor and outgoing dial tests at source 80120be. U13.03 and historical R5.07 accepted. Runtime lifecycle docs are extended, with U16.08 still awaiting source-backed full docs verification. **60/175 accepted, 115 open; 28 to 50.3%.**

**H2 staged HTTP/1.1 writer (2026-10-24):** The first transport-specific request serializer now builds bounded canonical H1 heads, rejects Host spoofing/framing conflicts and implicit H2-to-H1 downgrade, emits streaming chunked binary bodies and safe trailers. It reuses the U07.01 client contracts; **no U07.02 acceptance** until the response parser, real-peer interoperability and complete QA land. Source staged alongside filesystem F1 so one next-head run can validate related preparatory work. Overall **60/175 accepted, 115 open**.

**H3 staged HTTP/1.1 response parser (2026-10-24):** A bounded, streaming response decoder complements the H2 request writer with explicit CL/TE smuggling rejection, chunked/trailer handling, premature EOF and keepalive controls. Unit fixtures alone do not accept U07.02/.03 or substitute for real connected HTTP/1.1 interoperability. Continue rolling QA after Q2-J exact head and F1/H1/H2 group. **60/175 accepted, 115 open.**

**Q2-J QUIC real negative capability closeout (2026-10-24):** [Security & Standards #40064842606](https://github.com/infocyph/Runwire/actions/runs/40064842606) passed real independent aioquic, native reset followed by peer-received uni FIN, all PHPForge QA and both analyzers for source 3a9d9a7. This accepts U05.06/.07/.08 **only as explicit unsupported backend capabilities**: RFC9221 datagrams, path migration/CID rebinding and early 0-RTT remain unavailable and fail closed, not silently emulated. U05.05/.09/.10 and full certified QUIC/H3 performance remain OPEN; Q2-J extended Benchmarks tail must be checked separately. **Canonical 63/175 (36.0%), 112 open; 25 more for 50.3%.** New filesystem F1 and HTTP request/response parser H2/H3 source remain staged/unaccepted while Q2-J long diagnostics complete.

**H4 real TCP-peer H1 wire parity staged (2026-10-24):** Ephemeral localhost socket peer now checks the outbound serializer's exact fragmented binary chunked POST and sends a fragmented response/trailer consumed by the bounded parser. This bridges unit-only framing toward real protocol acceptance; U07.02 is not yet considered complete without a working nonblocking outbound exchange and exact-head QA. **63/175 accepted, 112 open.**

**F2 scope-owned filesystem adapter staged (2026-10-24):** `ScopedFiles` awaits the same off-loop worker operations using the existing Fiber scheduler and cancellation owner. Binary fixture checks five operations and process/watch cleanup. No U11 acceptance before real current-head QA and missing open/sandbox/durable-stream guarantees. **63/175 accepted, 112 open.**

**E1 QA recovery staged (2026-10-24):** The 9e3ec7e source fails PHPForge Pint and PHPStan complexity on new HTTP/1.1 and filesystem code; functional Pest/PHPCS/Rector green. Split H1 header/trailer validation into bounded stateless parser, simplify body-state dispatcher, normalize class methods and filesystem callbacks while maintaining wire semantics. Group this with H4 TCP peer and F2 scoped async file work in the next push after long Benchmark completion. No QA-incomplete tasks accepted; **63/175, 112 open**.

**W1 old H1 WebSocket interoperability closeout (2026-10-24):** Existing real socket-pair upgrade/handshake, masking, fragmented messages, ping/pong, graceful close and idle-timeout tests `tests/WebSocketIntegrationTest.php` PASS under four PHPForge QA lanes and both analyzers at immutable source 3a9d9a7 [#40064842606](https://github.com/infocyph/Runwire/actions/runs/40064842606). Accept **U08.10 only**; U09 work, outbound WS and WT remain OPEN. **64/175 accepted, 111 open, 24 additional to the 88-task milestone.** Ongoing E1 new-client QA recovery stays separate.

**S1 immutable H1/H2 source parity acceptance (2026-10-24):** Published 2.1.2 `Http1ConnectionTest.php` and `Http2ConnectionTest.php` are **blob-identical** to 3.0 while the current H1 and H2 servers use shared `Network\\Connection`, one H2 `FlowController` and one H2 `ResponseScheduler`. [Security #40064842606](https://github.com/infocyph/Runwire/actions/runs/40064842606) passed all four PHPForge QA lanes, analyzers and protocol fixtures. Accept U08.01/.02 in addition to U08.10; U08.07 stays OPEN because `AdaptiveProtocolPolicy` still defaults AUTO pending certification. Canonical **66/175 accepted (37.7%), 109 open; 22 to milestone**.

**H3 hosted FrankenPHP accepted (2026-10-24):** [Real Hosted Runtime Acceptance #40064842432](https://github.com/infocyph/Runwire/actions/runs/40064842432) PHP8.4/8.5 real FrankenPHP workers served persistent PID, incrementing sequence and fresh HTTP request target across three separate requests. U13.05 accepted without pretending RoadRunner/FPM pooled files/sockets are certified. **67/175 accepted, 108 open; 21 more to 88/175.**

**F3 real 1 MiB binary file offset/hash regression staged (2026-10-24):** Off-loop file worker is exercised at its full 1 MiB API cap with binary NUL/0xFF and SHA-256 parity, including no worker/watch leak. This will support U11.04 once full exact-head QA is green; it does not claim unbounded streams or durability. **67/175 accepted, 108 open.**

**S2 immutable supervisor recovery evidence (2026-10-24):** Published 2.1.2 crash/restart and rolling-generation corpus exactly matches the 3.0 source blobs; full QA and independent native SIGKILL/restart/backoff acceptance green at immutable source 3a9d9a7 [#40064842606](https://github.com/infocyph/Runwire/actions/runs/40064842606). U12.01 accepted, U12.05 stop-in-flight and all autoscale tasks remain open. **68/175 accepted, 107 open; 20 to 50.3%.**

**E2 QA remediation grouped with F3 (2026-10-24):** Correct 35e1a393 PHPForge Pint, PHPCS and PHPStan findings in the next push and add 1 MiB binary worker parity regression. All previous native H3+QUIC and other ordinary workflows passed; Security & Standards stayed red. No new task counted until QA. **68/175 accepted, 107 open.**

**F4 file-network bridge staging (2026-10-24):** Single bounded coroutine file/network transfer facade with real binary paired-socket hash fixtures, 16 KiB chunks and connection-pressure handling. U11.05 stays OPEN until slow-peer and full QA pass. **68/175 accepted, 107 open.**

**F5 slow receiver acceptance staged (2026-10-24):** A 2 MiB wire/file SHA-256 fixture now withholds receiver reads long enough to require real bounded network backpressure, then resumes without blocking event-loop heartbeat. U11.05 still QA-gated. **68/175 accepted, 107 open.**

**E3 grouped QA correction (2026-10-24):** A 1 MiB binary IPC packet exceeded fixed limits due to escaped base64 slashes. Use JSON_UNESCAPED_SLASHES end-to-end, preserving caps. Fix ScopedFiles return shape and API docs, head-parser redundant coalescing, and PHPForge statement spacing. Alongside F4/F5 real wire transfer and slow-peer regression, queue next CI; accept nothing until evidence is green. **68/175 accepted, 107 open.**

**H4 host transport matrix staged (2026-10-24):** Added a Sphinx-listed development host capability and limitation matrix, correctly separating verified host modes from unimplemented outbound clients, WT, migration/datagrams and native file AIO. U13.09 remains open until docs QA and cross-check. **68/175, 107 open**.

**P1 native/portable PHP8.4/8.5 accepted (2026-10-24):** Prior immutable green #40064842606/ #40064842530 proves native Select + PCNTL supervisor, PCNTL-free portable transports/TLS and file/process regression with real protocol peers. Accept U13.01; do not infer ext-event total parity or 8.4/8.5 archive prefer-lowest consumer tests. **69/175 accepted (39.4%), 106 open; 19 to milestone.**

**P2 standalone Composer archives accepted (2026-10-24):** Runwire requires no versioned runtime packages beyond PHP64 8.4+, so dependency resolver stable and lowest graphs are identical. Independently built/copy-installed candidate archives served native H1 200/400 on both PHP versions in green Release Candidate #40064842484 and #40067446052. Full stable/lowest PHPForge green at #40064842606. U13.08 accepted with no duplicate CI matrix; 3.0 remains unpublished. **70/175 (40.0%), 105 open; 18 to milestone.**

**D1 documentation QA recovery (2026-10-24):** Fix the only Sphinx -n -W build error from #40068020540 (file/network RST title underline too short) with zero semantic changes. Group with next coherent QA batch. **70/175 accepted, 105 open.**

**S3 H1/H2/H3 unchanged safety caps accepted (2026-10-24):** Published 2.1.2 versus green 3.0 source shows identical H1/H2/H3 request/body/frame limit classes, shared connection/worker budgets and original abuse fixtures; new H2 earned control-frame credit test also passes [#40064842606](https://github.com/infocyph/Runwire/actions/runs/40064842606). U08.06 accepted for server-side preservation only. **71/175 accepted, 104 open, 17 more to 50.3%.**

**H5 documented supervisor policy and honest autoscale limitations (2026-10-24):** A native RST lifecycle diagram now distinguishes restart budget, readiness and drain, recycle triggers and host-owned vs native processes without advertising unimplemented autoscale. U16.10 still QA-gated. **71/175, 104 open.**

**F6 file stream offset validation (2026-10-24):** Fail closed on offset+transfer integer overflow with a regression assertion before socket/file I/O; no new accepted tasks until CI. **71/175, 104 open.**

**E4 source QA remediation staged (2026-10-24):** Fix 3f66c72 Pest 300-second root-coroutine hang by retiring external peer watcher/heartbeat before Fiber completion and last PHPForge ScopedFiles constructor/brace style problem. Sphinx heading fix already staged. U11 filesystem transfer remains QA-unaccepted. **71/175 accepted, 104 open.**

**H6 verified docs/host (2026-10-24):** Native host capabilities and supervised lifecycle pages from source 96979be pass [strict Sphinx #40068643157](https://github.com/infocyph/Runwire/actions/runs/40068643157); U13.09 and U16.10 accepted only as accurate documentation, not automatic feature implementation. **73/175 accepted, 102 open; 15 to 50.3%.**

**H8 FPM resource cleanup staged (2026-10-24):** Real PHP8.4/8.5 FastCGI fixture gains per-request temporary file and paired Unix-socket round trips, followed by verified cleanup, while retaining fresh-sequence assertions across three requests. U13.06 remains QA pending. **73/175 accepted, 102 open.**

**F9 active-file-job cancellation staged (2026-10-24):** Real FIFO-blocked file read cancellation with one terminal callback and worker/watch cleanup added alongside queued deadline tests, skipped on systems without POSIX FIFO support. No U11.06 acceptance until CI. **73/175, 102 open.**

**F10 certified functionality (2026-10-24):** Exact source 96979be full PHPForge/security/real H3-QUIC [#40068643399](https://github.com/infocyph/Runwire/actions/runs/40068643399) and strict Sphinx [#40068643157](https://github.com/infocyph/Runwire/actions/runs/40068643157) both green; accept U07.02, U11.02/.04/.05 and U16.08/.09 with bounded/host-specific scope. No completed connected H1/H2/H3 clients, filesystem sandbox/durability or frozen performance claims. **79/175 accepted (45.1%); 96 open, 9 to 50.3%.**

**H9 actual RoadRunner scoped resource cleanup staged (2026-10-24):** Real persistent PHP8.4/8.5 worker fixture now verifies request file and Unix-socket roundtrips/close plus previous request file cleanup, enforced in hosted CI. U13.04 not promoted until QA. **79/175 accepted, 96 open**.

**E5 QA scanner correction and real-FPM acceptance (2026-10-24):** Remove disallowed skip directive from mandatory POSIX FIFO cancel fixture: all original PHPForge Pest suites passed but scanner refused `markTestSkipped` at exact a18c95f #40069276513. Accept real FPM per-request file/socket cleanup on PHP8.4/8.5 #40069276256 as U13.06. No claim of RoadRunner pooled async handles or new FIFO cancellation proof until full QA green. **80/175 accepted, 95 open; 8 to 50.3%.**

**P3 safe pool identity key staged (2026-10-24):** Separate typed isolation keys bind destination, scope, TLS peer/trust ALPN and proxy authorization revision without storing secrets, with negative/cross-tenant unit tests. No pool functionality advertised; U10.01 awaits next exact-head QA. **80/175 accepted, 95 open.**

**S4 host-owned worker correctness (2026-10-24):** U12.07 accepted from native-only supervisor gating and real PCNTL-free/hosted PHP8.4/8.5 CI, without implying actual autoscaling. P3 pool isolation key remains QA-gated. **81/175 accepted (46.3%), 94 open, seven to milestone.**

**F11 accepted real active FIFO/queued file cancellation and full worker/host file-handle cleanup (2026-10-24):** 439408e four PHPForge QA+2 analyzers green #40070492152; real host PHP8.4/8.5 cleanup #40070491774 green. U11.06/.07 accepted; open/durable sandbox/benchmark remain open. P3 PoolIsolationKey next QA. **83/175 (47.4%), 92 open; five to 50.3%.**

**S5 autoscale policy-only contract staged (2026-10-24):** CPU/queue/RPS/p99, min/max, hysteresis/cooldown and fixed operator override validated by PHP tests and truthful host RST. No scaling activated; U12.03 QA-gated. **83/175 accepted, 92 open.**

**F12 rename/durability boundaries staged (2026-10-24):** Explicit native RST OS rename vs fsync/parent-directory crash safety limitations and real replace/failure worker test. No fake atomic-durability support; U11.08 QA gated. **83/175 accepted, 92 open.**

**H10 optional extensions negative matrix staged (2026-10-24):** Two lightweight checks in existing portable workflow: php -n must still run Unix streams without ext-sockets; disabled POSIX API must reject prefork but allow native single-process. Existing TLS/PCNTL/QUIC test lanes untouched. U13.07 awaits green. **83/175 accepted, 92 open.**

**D2 feature catalog QA fixture staged (2026-10-24):** Parser asserts all native-RST five-column per-feature status/host/extension cells across 29 required capabilities with no duplicates; stable vs dev is explicit. U16.04 remains unchecked until exact-head PHPForge. **83/175 accepted, 92 open.**

**E6 (2026-10-24):** One PHPStan proxy host nullsafe fix after 7f84bea, pending next QA. **83/175 accepted, 92 open.**

**S6 pure autoscale decision engine staged (2026-10-24):** CPU/queue/p99/RPS target decisions with cooldown and policy bounds, no supervisor connection yet; U12.04 still unaccepted. **83/175, 92 open**.

**H11 optional event loop matrix staged (2026-10-24):** Existing accelerated-event job checks real PECL event PHP8.4 and PHP8.5 with event-loop and H1 worker budget suite; portable Select lanes unchanged, extension optional. U13.02 QA gated. **83/175, 92 open.**

**H12 truthful feature catalog sync staged (2026-10-24):** Status now distinguishes development-only pool isolation and pure autoscale target algorithm from unimplemented working pools/worker scaling. D2 coverage and strict Sphinx still QA-gated. **83/175 accepted, 92 open.**

**M1 50.3% milestone (2026-10-24):** PHPForge four QA and both static analyzers, strict Sphinx and portable extension negatives passed at c2ce077 #40071567489/#40071567309/#40071567310. U10.01 pool isolation keys, U11.08 explicit fsync/rename limitations, U12.03 native autoscale policy only, U13.07 optional extension portability, U16.04 complete versioned feature categories accepted. **Real live pool/scale/durability remain open. 88/175 (50.3%), 87 open.**

**D3 RST network guide staged (2026-10-24):** Detailed real fixture-linked TCP/TLS/Unix/UDP/QUIC and DNS IPv4/IPv6 semantics, transport errors/owners/extensions. U16.05 QA pending. **88/175, 87 open.**

**H11 green optional event/native loop parity (2026-10-24):** Real PHP8.4/8.5 PECL ext-event plus matching EventLoop/H1 worker budget regressions vs no-ext-event Select in green #40071912799. U13.02 accepted for tested equivalence only; U13.04 persistent pooled RoadRunner remains open. **89/175 (50.9%), 86 open.**

**P4 toward 75% (2026-10-24):** Green 00291e1 source accepts U08.07 fixed adaptive defaults and U16.05 real transport/DNS guide, with pinned Sphinx/PHPForge evidence. **91/175 (52.0%), 84 open, 41 to 75.4%.**

**P5 protocol + WT docs staged (2026-10-24):** Source-backed H1/H2/H3/WS docs and unimplemented WebTransport ownership design, pending strict QA. **91/175 accepted, 84 open.**

**S7 SSRF candidate+peer rebinding guard staged (2026-10-24):** Opt-in pre-socket post-connect address checks and negative tests, no silent default; U14.01 QA pending. **91/175, 84 open.**

**P6 hosted proxy/relay policy docs + H2/H3 CONNECT negative tests staged (2026-10-24):** Clear unsupported status and runnable tests. U09.04 and U16.07 QA pending. **91/175, 84 open.**

**S7 PHPForge QA corrections staged (2026-10-24):** Extract bounded address preflight from dial connect (cognitive complexity) and Pint spacing fix. Waiting exact-head QA, U14.01 not yet accepted. **91/175, 84 open.**

**P7 (2026-10-24):** Sphinx e26477b strict green #40073761998 accepts U09.05 WT architecture only and U16.06 HTTP/WS/WT documentation. Actual WT and extended CONNECT remain unsupported. Other P6/S7 tasks code-QA gated. **93/175 accepted (53.1%), 82 open, 39 to 75.4%.**

**P8 H3 shared owner and trust test acceptance (2026-10-24):** U08.03 H3 native peer-reset/sibling ownership, U14.02 TLS/mTLS/ALPN and proxy-key negatives accepted on 00291e1 all green #40072263291. Full H3 client/proxy tunnel not claimed. **95/175 (54.3%), 80 open.**

**W1 shared WebSocket parser/encoder staged (2026-10-24):** Masked client writer and same bounded parser in role-dependent mask mode, binary/control negatives. U09.01 QA gated, U09.02 client still open. **95/175 accepted, 80 open.**

**W2 shared session role staged (2026-10-24):** One WebSocketSession lifecycle can now use client mask direction with real loopback duplex test; no H1 upgrade client. U09.01 QA pending. **95/175, 80 open.**

**P9 (2026-10-24):** U09.04/U14.01/U16.07 accepted on exact 8ea14d1 [Security #40073762272](https://github.com/infocyph/Runwire/actions/runs/40073762272) and [Sphinx #40073761998](https://github.com/infocyph/Runwire/actions/runs/40073761998). Live pool/proxy client and WebTransport still OPEN. **98/175 (56.0%), 77 open.**

**D4 RST snippet source validation staged (2026-10-24):** Parse PHP examples via PHP TOKEN_PARSE, require referenced test/benchmark files, leverage strict Sphinx links in existing CI. U16.15 pending QA. **98/175, 77 open.**

**D5 source-linked runbooks and performance/security guide staged (2026-10-24):** Real runnable portable/host/file examples and no fabricated throughput results, benchmark provenance + threat model. U16.13/U16.14 QA pending. **98/175, 77 open.**

**W3 WebSocket QA correction staged (2026-10-24):** Correct e5c4a302 Pest/Pint/PHPCS/PHPStan issues on WebSocket framing while preserving strict direction masking, with extra role regression. U09 pending exact-head QA. **98/175, 77 open; 34 to 75%.**

**W4 RFC6455 adversarial matrix staged (2026-10-24):** Binary frame boundary, masking-direction, fragmented read, error-code, worker-memory budget fuzz-style fixtures. U09.01/U14.04 remain open pending QA. **98/175 accepted, 77 open**.

**D4 snippet correctness suite staged (2026-10-24):** PHP native syntax checks for RST PHP examples and real test/benchmark file reference validation in PHPForge matrix; strict Sphinx remains required. U16.15 pending green, **98/175 accepted, 77 open**.

**W6 WebSocket multi-connection regression staged (2026-10-24):** 64 concurrent paired sockets, interleaved ping/fragmentation, exact delivery and owner cleanup, QA pending. U09.03 remains open. **98/175 accepted, 77 open.**

**H13 RoadRunner live FD lifecycle regression staged (2026-10-24):** PHP8.4/8.5 real worker now records `/proc/self/fd` before/after, rejects growth per request or cumulative across repeated requests, alongside socket and file cleanup. Real pooled outgoing clients are still unimplemented, so U13.04 stays open. **98/175 accepted.**

**W7 docs + WebSocket real QA accepted (2026-10-24):** PHPForge 8.4/8.5 four test/analysis configurations #40076825500 and strict Sphinx #40076825279 passed on immutable a0812bc. Accept U09.01 shared RFC6455 frame/close masking and U16.15 PHP/RST runnable reference syntax plus Sphinx links. No claims for incomplete WebTransport, hosted docs or new 64-socket W6 fixture. **100/175 accepted (57.1%), 75 open, 32 to milestone.**

**W8 outbound WebSocket handshake staged (2026-10-24):** Stateless request nonce and 101 accept/subprotocol validation with negative tests; no connected client yet. U09.02 QA pending/open. **100/175 accepted, 75 open.**

**W9 real socket RFC6455 header peer staged (2026-10-24):** Actual socket pair sends client upgrade and independently computes 101 acceptance; no connected WS client claim. U09.02 OPEN. **100/175, 75 open.**

**W10 backpressure and bounded WebSocket send staged (2026-10-24):** Real initially stalled stream with 4KiB send hard cap, PRESSURED/reject assertion, then peer-drain callback and zero watcher/timer cleanup; W6 64 connections previously staged. U09.03 QA pending. **100/175, 75 open.**

**E7 QA fix staged (2026-10-24):** Refactor W8 URI complexity and redundant port check, apply Rector callable, drive actual OS socket saturation for W10 pressure fixture. Counters unchanged until full green. **100/175, 75 open.**

**T0 tracker/QA head verification (2026-10-24):** Checked existing PR #9 head `5259457455ee`: all eight routine PR workflows PASS ([Security & Standards #40078000383](https://github.com/infocyph/Runwire/actions/runs/40078000383), [Benchmarks #40078000285](https://github.com/infocyph/Runwire/actions/runs/40078000285), [Hosted #40078000245](https://github.com/infocyph/Runwire/actions/runs/40078000245), plus native, Sphinx, source audit, Swoole and package). No unresolved PR review threads. Fix historical stale U06 delivery-row tally in roadmap and add exact per-batch roadmap regression coverage to `tests/Plan3TrackerSynchronizationTest.php`. Full matched-TLS and release-duration certification remain OPEN because manual gates are not proven by routine green. No new Uxx acceptance: **100/175 (57.1%), 75 open**. Next grouped implementation W11: connect the verified outbound WebSocket handshake to real TCP/TLS dialing and a client-owned shared session; test independent peer, cancellation and buffered upgrade data.

**W11 outbound WebSocket client integration (2026-10-24; QA pending):** Added `WebSocketClient` over the existing `HappyEyeballsDialer` and verified TCP/TLS policy; `WebSocketClientUpgrade` validates a bounded real HTTP/1.1 101 handshake, coalesced first frame, timeout/cancellation and exactly-once failure/ownership handoff to the common `WebSocketSession` with client masking. Added independent real TCP response/frame roundtrip and WSS host/ALPN fail-closed negatives. Fixed the T0 roadmap-regression regex escape before acceptance. U09.02 remains OPEN pending exact-source CI, independent WSS peer conformance and full lifecycle QA; U09.03 still requires sustained pressure proof. Canonical **100/175 (57.1%), 75 open**.

**W12 outbound WebSocket bounded-header hardening (2026-10-24; QA pending):** Cap upgrade response reads at remaining 8 KiB header budget plus one byte, preserving unread coalesced frame bytes for the session. Add explicit oversized independent stream regression. This does not accept U09.02 until all head CI checks pass; **100/175 accepted, 75 open**.

**W13 test correction (2026-10-24; QA pending):** Correct oversized-header regression to send literal CRLF wire separators rather than backslash-escaped text; preserves meaningful HTTP upgrade parser coverage. No new acceptance pending fresh CI. **100/175 accepted, 75 open**.

**W14 CI remediation (2026-10-24; QA pending):** GitHub Actions `Security & Standards` #40080183686 failed in PHP 8.4/8.5 QA despite **807 Pest passes** and successful WebSocket integration. Exact findings: `WebSocketClientUpgrade::start()` missing public docblock; Pint `ordered_class_elements`; PHPCS two unused callback parameters in `WebSocketOutboundClientTest.php`. Addressed all four source-level issues and preserved W12/W13 integration coverage. Other seven workflows green at prior head. Do not accept U09.02 until the remediation head passes all checks. **100/175 accepted, 75 open**.

**W15 exact-head QA regression remediation (2026-10-24; pending new CI):** Security & Standards [#40082011299](https://github.com/infocyph/Runwire/actions/runs/40082011299) at 335aa00 failed 4/4 PHPForge lanes (806 Pest passes, 2 test failures, Pint/Rector). Root causes from immutable logs: W14 callback warning fix accidentally changed `$seen[]` and `$errors[]` to scalar assignment; restore append semantics. Put private methods in alphabetical order (`consume`, `fail`, `finish`) for Pint `ordered_class_elements`; use PHP 8.4 `new WebSocketClientUpgrade(...)->start()` for Rector `NewMethodCallWithoutParenthesesRector`. All seven other workflows passed at prior head; no acceptance before all green on this head. **100/175 accepted, 75 open**.

**W16 WebSocket cancellation/EOF lifecycle QA (2026-10-24; staged):** Add real independent Unix stream tests verifying cancellation of a pending outbound HTTP 101 upgrade, exactly-once callback despite duplicate cancellation/abort, token unsubscription and zero reactor handles; verify peer EOF fails closed with exactly-once callback and no read/write watcher leak. Existing W11 peer roundtrip, W12 oversized response and W10 pressure suites retained. QA still pending and WSS independent TLS conformance remains required for U09.02; no new acceptance. **100/175 accepted, 75 open**.

**W17 real verified WSS peer QA (2026-10-24; staged):** Add independent scripted RFC6455 HTTP 101 responder over a real `TcpListener` TLS server with ephemeral trusted localhost identity and HTTP/1.1 ALPN. Exercise `WebSocketClient::connect` through real async DNS/Happy Eyeballs, strict peer-name/CA verification, negotiated `chat` subprotocol, coalesced TLS text frame and peer-initiated close code 1000/reason `done`, plus ownership cleanup. Complements W11 plain TCP and W16 cancellation/EOF. Await exact-head PHPForge and hosted CI before U09.02 acceptance; **100/175 accepted, 75 open**.

**W18 WebSocket WSS QA recovery + bounded bidirectional relay (2026-10-24; QA pending):** Security & Standards [#40095684307](https://github.com/infocyph/Runwire/actions/runs/40095684307) at 6bc41a4 had four PHPForge Pest lane failures because the W17 WSS fixture never observed a completed TLS server session within its watchdog; source audit/docs/hosted/native/Swoole/release passed. Replace external `localhost` DNS dependency with deterministic loopback IP endpoint and an explicit signed certificate SAN `IP:127.0.0.1`, preserving exact TLS host/CA/ALPN policy; match PHPForge Pint alphabetical private mutable-property order in upgrade handler. Introduce `Network\\StreamRelay`: bounded full-duplex forwarding using the existing shared `Connection` receive/send queues, explicit backpressure read pause and drain resume, per-tick forwarding limit, exclusive callback handoff and fail-closed dual abort. Independent paired Unix stream tests check simultaneous hash-correct messages, exact byte accounting and no watcher/timer leaks. Half-close, cancellation and staged shutdown remain U10.08 OPEN; full U10.07 performance/large-transfer acceptance and U09.02 WSS QA remain OPEN until exact-head verification. Canonical **100/175 (57.1%), 75 open**.

**W19 bounded relay saturation/large-transfer verification (2026-10-24; QA staged):** Add independent 1 MiB SHA-256 end-to-end relay test with queued asynchronous source writes, downstream 80 ms withheld reads, 64 KiB send/receive hard caps, exact byte counters, bounded peak queue, zero rejected writes, cleanup and a 5s watchdog. Update RST relay section with implementation/ownership limits and explicitly unsupported half-close/cancellation; U10.07 requires exact-source PHPForge and resource-pressure acceptance, U10.08 remains OPEN. No fabricated acceptances: **100/175 (57.1%), 75 open**.

**W20 W18 PHPForge feedback combined with W19 (2026-10-24; QA staged):** [Security #40096136736](https://github.com/infocyph/Runwire/actions/runs/40096136736) at 09bdb17 failed both PHPStan analysis lanes because `StreamRelay::transfer` cognitive complexity 16 exceeds 12; split chunk transfer and backpressure into focused single-responsibility methods without relaxing budgets. PHPForge 8.4 lowest Pest reported one WSS TLS fixture failure (812 passed): the independent TLS server callback was not entered. Include explicit sanitized fixture diagnostics (server accepted/rejected/pending TLS and client error messages) instead of guessing about certificate/DNS. Pint flagged inline closure braces in new relay; use multiline closures. W19 includes 1 MiB hash/64 KiB queue pressure test and RST feature boundaries. All unaccepted until exact-source QA; **100/175, 75 open, 32 to 75.4%**.

**W21 relay cancellation/deadline lifecycle (2026-10-24; staged, unaccepted):** Extend `Network\\StreamRelay` with optional inherited `CancellationToken` and `RequestDeadline`, register one cancellation observer and one deadline timer at `start()`, release both on all close/partial-start paths, and abort both socket owners exactly once. Real Unix stream tests verify cancellation subscriptions, deadline-driven closure, no watcher/timer leaks. Half-close and graceful one-sided draining remain explicitly unimplemented U10.08; status **100/175, 75 open**, pending exact-head QA.

**W22 exact cause of WSS and relay Pint failure (2026-10-24; QA pending):** [Security & Standards #40096500963](https://github.com/infocyph/Runwire/actions/runs/40096500963) at 8378e0a passed both PHPStan/Psalm 8.4/8.5 analyses, 813 Pest tests, and PHPCS/Rector, but 1 WSS fixture failed because `stream_socket_enable_crypto()` inherited a stale **local TLS certificate path from an unrelated prior test** (`/tmp/runwire-client-tls-.../cert.pem`). Root issue: `Dialer::open()` called `stream_socket_client` without an explicit stream context, allowing PHP's process-global default TLS options to leak into unrelated outbound connections. Always create an independent `stream_context_create()` for every numeric/Unix dial before per-connection `TlsClientOptions` are applied; no trust relaxation or plaintext fallback. Pint `StreamRelay` requires a blank before `try`; the staged W21 lifecycle includes that correction. W19 1 MiB hash/pressure relay and W21 token/deadline cleanup land together in next QA. No acceptance until full exact-head green; **100/175, 75 open, target 132**.
