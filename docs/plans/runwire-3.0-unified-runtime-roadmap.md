# Runwire 3.0 — consolidated unified-runtime roadmap

**Decision (2026-10-23):** The next planned stable release is **3.0.0**, compared directly to published **2.1.2** at commit 7e9bf20ad0737a187dfed77b723791b141b36378. **Do not release 2.2.0.** Preserve the source work and evidence produced under its unfinished development plan.
**Work location:** infocyph/Runwire only. **Stay on the existing plan/runwire-independent-ecosystem-roadmaps branch and draft PR #9.** No new branch or changes to Foundation, Infbyte, Runwire-Core, Runwire-Cells or other repositories.
**Implementation:** **U00 COMPLETE (10/10)**. **13/175 accepted tasks, 162 open across 18 batches** (7.4%). No 3.0 unified transport is yet implemented. The [detailed 3.0 plan and canonical task tracker](runwire-3.0-unified-runtime-development-plan.md) is the one progress source of truth.

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
| R5.02 | U03.01 | OPEN, fresh 3.0 QA required |
| R5.03 | U02.06 + U06.03 | OPEN, fresh 3.0 QA required |
| R5.04 | U02.04 | OPEN, fresh 3.0 QA required |
| R5.05 | U04.02 + U04.06 | OPEN, fresh 3.0 QA required |
| R5.06 | U03.08 | OPEN, fresh 3.0 QA required |
| R5.07 | U03.09 + U13.03 | OPEN, fresh 3.0 QA required |
| R5.08 | U03.10 + U15.06 | OPEN, fresh 3.0 QA required |
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
| U01 | Event-loop, watchers and asynchronous executor | 6 | 3/9 |
| U02 | Unified established connection and transport ownership | 9 | 0/9 |
| U03 | Async DNS and outbound TCP/Unix dialer | 10 | 0/10 |
| U04 | Unified TLS security and client/server handshakes | 8 | 0/8 |
| U05 | UDP, outbound QUIC and unified datagram transport | 10 | 0/10 |
| U06 | Structured concurrency, tasks, deadlines and scopes | 8 | 0/8 |
| U07 | HTTP/1.1, HTTP/2 and HTTP/3 clients | 11 | 0/11 |
| U08 | Existing H1/H2/H3 server consolidation | 10 | 0/10 |
| U09 | WebSocket and WebTransport client/server | 10 | 0/10 |
| U10 | Connection pooling, proxy negotiation and stream relay | 9 | 0/9 |
| U11 | Truly asynchronous filesystem and file streaming | 10 | 0/10 |
| U12 | Native supervisor and adaptive worker autoscaling | 9 | 0/9 |
| U13 | Real host and optional extension compatibility | 9 | 0/9 |
| U14 | Security, fuzzing and adversarial resilience | 8 | 0/8 |
| U15 | Performance attribution, regressions and soak | 10 | 0/10 |
| U16 | Read the Docs-compatible Sphinx documentation and full feature catalog | 16 | 0/16 |
| U17 | Final certification, risk signoff and maintainer handoff | 9 | 0/9 |
| **TOTAL** | **Full unified-runtime release program** | **162** | **13/175 (7.4%)** |

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

**U01 partial implementation evidence (2026-10-23):** [RST source-backed loop ownership matrix](evidence/runwire-3.0/u01-loop-backend-audit.rst), exact watcher cancel/dispatch probe in `tests/LoopBackendConformanceTest.php`, [Swoole/OpenSwoole real host QA](https://github.com/infocyph/Runwire/actions/runs/39908150999) and PHPForge QA/analysis passes in [Security & Standards #39908151527](https://github.com/infocyph/Runwire/actions/runs/39908151527). **U01 3/9 only**, no async executor implemented yet; do not start U02 without verified U01 closure.
