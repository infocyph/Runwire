# Runwire 3.0 — unified runtime detailed development plan and tracker

**Target:** Runwire 3.0.0 after published 2.1.2 (commit 7e9bf20ad0737a187dfed77b723791b141b36378); 2.2 is an unpublished historical engineering candidate.
**Location:** infocyph/Runwire only, existing branch plan/runwire-independent-ecosystem-roadmaps and existing draft PR #9. No merge, new branch, tag, release or updates to Foundation, Infbyte, Runwire-Core or Runwire-Cells.
**Date:** 2026-10-23. **State:** U00 IN PROGRESS. Seven source-audit, historical reconciliation, API-migration and contract-design tasks accepted; no unified 3.0 transport functionality has been implemented.
**Canonical tracker:** 7/175 complete; 168 open; 18 batches. Prior 2.2 counts are historical and do not count toward 3.0.
**Decision index:** [Consolidation roadmap](runwire-3.0-unified-runtime-roadmap.md).

## Development rules

- One established-connection engine for incoming and outgoing streams. Connection attempts, client/server TLS roles, UDP/QUIC and protocol state machines remain separate where behavior demands it.
- Public API breaking changes are allowed for a coherent architecture, but every changed API needs a migration guide and tests.
- No hidden blocking: DNS and filesystem use genuine non-event-loop execution or explicitly reject unsupported modes; keep optional native extensions optional.
- Full 3.0 scope includes networking (TCP/TLS/Unix/UDP/QUIC), async DNS, dialing, pooling, proxies, relay, HTTP1/2/3, WebSocket and WebTransport clients/servers, filesystem, Fiber concurrency, autoscaling, all hosted integrations, security, observability and complete docs.
- 64-bit PHP 8.4+ and Composer remain the baseline. No new dependency or release gate on unrelated Infocyph repositories.
- Implement one batch, run focused tests and PHPForge QA, inspect exact run IDs, then commit code, accepted evidence and updated tracker together. Only checked tasks may count as complete.
- Keep 2.1.2 as immutable baseline. Final matched throughput requires five 180-second pairs, warmup, successful RPS, both CV below 2.5%, strict p99/RSS/correctness budgets, generator headroom, and 1800-second process-tree soak. A passing mock or microbenchmark cannot replace real protocol QA.
- **Sphinx documentation is mandatory:** create Read the Docs-compatible Sphinx with configuration v2, complete detailed feature list, generated PHP API reference, migration guides and strict docs CI. All U16 tasks are required for 3.0.
- **Task proof convention:** change [ ] to [x] only after Acceptance is verified and update Proof from PENDING to immutable commit, real test and Actions/artifact link. No plan-only task completion.

## Canonical batch summary

| Batch | Division | Done / total | Open | Status | Dependencies |
| --- | --- | ---: | ---: | --- | --- |
| U00 | Baseline, architecture and 2.1-to-3.0 migration | 7/10 | 3 | In progress | published 2.1.2; historical 2.2 tracker |
| U01 | Event-loop, watchers and asynchronous executor | 0/9 | 9 | Not started | U00 |
| U02 | Unified established connection and transport ownership | 0/9 | 9 | Not started | U01 |
| U03 | Async DNS and outbound TCP/Unix dialer | 0/10 | 10 | Not started | U01,U02 |
| U04 | Unified TLS security and client/server handshakes | 0/8 | 8 | Not started | U02,U03 |
| U05 | UDP, outbound QUIC and unified datagram transport | 0/10 | 10 | Not started | U02,U03,U04 |
| U06 | Structured concurrency, tasks, deadlines and scopes | 0/8 | 8 | Not started | U01,U02,U03 |
| U07 | HTTP/1.1, HTTP/2 and HTTP/3 clients | 0/11 | 11 | Not started | U03,U04,U05,U06 |
| U08 | Existing H1/H2/H3 server consolidation | 0/10 | 10 | Not started | U02,U04,U05,U06 |
| U09 | WebSocket and WebTransport client/server | 0/10 | 10 | Not started | U05,U07,U08 |
| U10 | Connection pooling, proxy negotiation and stream relay | 0/9 | 9 | Not started | U03,U04,U06,U07 |
| U11 | Truly asynchronous filesystem and file streaming | 0/10 | 10 | Not started | U01,U02,U06 |
| U12 | Native supervisor and adaptive worker autoscaling | 0/9 | 9 | Not started | U01,U02,U06 |
| U13 | Real host and optional extension compatibility | 0/9 | 9 | Not started | U01,U03,U04,U06,U12 |
| U14 | Security, fuzzing and adversarial resilience | 0/8 | 8 | Not started | U03-U13 |
| U15 | Performance attribution, regressions and soak | 0/10 | 10 | Not started | U02-U14 |
| U16 | Read the Docs-compatible Sphinx documentation and full feature catalog | 0/16 | 16 | Not started | U00-U15 |
| U17 | Final certification, risk signoff and maintainer handoff | 0/9 | 9 | Not started | U00-U16 |
| **TOTAL** | **Runwire 3.0.0 unified runtime** | **7/175** | **168** | **U00 in progress** | U00-U17 |

**Progress counter:** 7/175 (4.0% complete), 168 open.

## Detailed implementation acceptance checklist

### U00 — Baseline, architecture and 2.1-to-3.0 migration

**Prerequisite:** published 2.1.2; historical 2.2 tracker. **Batch QA:** targeted unit, fault and real endpoint tests; PHP8.4/8.5 stable/lowest PHPForge QA where relevant; CI evidence; tracker sync before proceeding.

- [x] **U00.01** Audit published 2.1.2 public classes, constructors, interfaces and errors. **Acceptance:** Store immutable API/signature baseline. **Proof:** [Source Audit #39904006992](https://github.com/infocyph/Runwire/actions/runs/39904006992) PASS; published 2.1.2 350 types, pinned checksum `a2348788547bb8e3c89a49db399862afc6a13f18fb0aa5470d27f89780871dd1`, committed [lock](evidence/runwire-3.0/public-api-2.1.2.lock.json), [full signature artifact](https://github.com/infocyph/Runwire/actions/runs/39904006992/artifacts/12479892848).
- [x] **U00.02** Classify historical 2.2 completed work as preserved, reworked or requiring requalification. **Acceptance:** All prior completed task groups have a disposition. **Proof:** [source-backed U00 audit](evidence/runwire-3.0/u00-architecture-baseline.md) section B classifies all 75 historical checked items by R0–R9 group, with 20 open crosswalk; [PHPForge QA #39904007423](https://github.com/infocyph/Runwire/actions/runs/39904007423) four QA lanes passed.
- [x] **U00.03** Reconcile exactly 20 unfinished 2.2 IDs into the 3.0 program. **Acceptance:** All 20 IDs have one or more mapped U tasks. **Proof:** [3.0 crosswalk](runwire-3.0-unified-runtime-roadmap.md) maps all 20/20 historical unchecked R task IDs; `tests/Plan3TrackerSynchronizationTest.php` verifies exact mapping; [PHPForge QA #39904007423](https://github.com/infocyph/Runwire/actions/runs/39904007423) passed.
- [ ] **U00.04** Specify pending-connect versus established-connection state machines. **Acceptance:** Signed diagrams and illegal-transition tests. **Proof:** PENDING.
- [ ] **U00.05** Design factory-owned connection creation and explicit socket ownership. **Acceptance:** No duplicate outbound and inbound I/O engine. **Proof:** PENDING.
- [x] **U00.06** Define intended breaks in LoopInterface, RuntimeContext, Connection, TLS and CoroutineScope. **Acceptance:** Before-and-after API migration guide. **Proof:** [before/after PHP migration draft](evidence/runwire-3.0/u00-public-api-migration-draft.md) records planned Connection, AsyncConnection, LoopInterface, TLS, RuntimeContext, CoroutineScope, RuntimeOptions behavior breaks; implementation remains U02+.
- [x] **U00.07** Define normal EOF, half-close, cancellation, timeouts, shutdown and resource budgets. **Acceptance:** Single contract per transport type. **Proof:** [unified resource contract](evidence/runwire-3.0/u00-resource-state-contract.md) specifies lifecycle, half-close, deadline/cancel, error and resource budgets for TCP/Unix/TLS/UDP/QUIC/DNS/filesystem; executable implementation remains U02+.
- [x] **U00.08** Inventory native/portable and all host-owned execution modes. **Acceptance:** Exact capability and unsupported-mode matrix. **Proof:** [source-backed runtime capability matrix](evidence/runwire-3.0/u00-architecture-baseline.md) section C plus [real host QA #39904006975](https://github.com/infocyph/Runwire/actions/runs/39904006975) PASS and [Swoole #39904007017](https://github.com/infocyph/Runwire/actions/runs/39904007017) PASS.
- [ ] **U00.09** Pin 2.1.2 benchmark comparator with matched topology. **Acceptance:** Reproducible hardware and load-generator inventory. **Proof:** PENDING.
- [x] **U00.10** Approve exact 3.0 target/scope and Runwire-only development boundary. **Acceptance:** PR scope and architecture decisions recorded. **Proof:** maintainer-approved 3.0 scope reflected in [draft PR #9](https://github.com/infocyph/Runwire/pull/9), [roadmap](runwire-3.0-unified-runtime-roadmap.md), archived 2.2 history and unchanged Runwire-only branch.

**Batch tracker:** 7/10 accepted; 3 open. Remaining U00.04 (illegal state-transition tests), U00.05 (single factory/owner verified without duplicate engines), U00.09 (current exact runner topology and generator headroom). **Exit:** all evidence linked, required QA green, no unresolved critical regression.

### U01 — Event-loop, watchers and asynchronous executor

**Prerequisite:** U00. **Batch QA:** targeted unit, fault and real endpoint tests; PHP8.4/8.5 stable/lowest PHPForge QA where relevant; CI evidence; tracker sync before proceeding.

- [ ] **U01.01** Compare SelectLoop, EventLoop and SwooleLoop event readiness semantics. **Acceptance:** Cross-backend behavior matrix. **Proof:** PENDING.
- [ ] **U01.02** Unify watcher registration, removal, deferred callback and timer ownership. **Acceptance:** No callback after cancellation. **Proof:** PENDING.
- [ ] **U01.03** Preserve fairness under CPU, socket and timer storms. **Acceptance:** Bounded socket starvation in real load fixtures. **Proof:** PENDING.
- [ ] **U01.04** Standardize monotonic operation deadlines and lifecycle shutdown. **Acceptance:** Timeouts and stop are deterministic. **Proof:** PENDING.
- [ ] **U01.05** Implement bounded asynchronous executor abstraction for blocking platform calls. **Acceptance:** Queue limits and cancellation rules. **Proof:** PENDING.
- [ ] **U01.06** Implement a portable worker-backed executor for DNS/filesystem. **Acceptance:** Deliberate blocking work never stalls event-loop ticks. **Proof:** PENDING.
- [ ] **U01.07** Exercise executor crash, overload, cancellation and drain. **Acceptance:** Zero unresolved tasks or leaked resources. **Proof:** PENDING.
- [ ] **U01.08** Respect host-owned reactors without nested competing loop. **Acceptance:** Swoole/OpenSwoole and host acceptance. **Proof:** PENDING.
- [ ] **U01.09** Profile scheduler, watcher churn and executor resource cost. **Acceptance:** CPU, RSS and p99 evidence. **Proof:** PENDING.

**Batch tracker:** 0/9 accepted; 9 open. **Exit:** all evidence linked, required QA green, no unresolved critical regression.

### U02 — Unified established connection and transport ownership

**Prerequisite:** U01. **Batch QA:** targeted unit, fault and real endpoint tests; PHP8.4/8.5 stable/lowest PHPForge QA where relevant; CI evidence; tracker sync before proceeding.

- [ ] **U02.01** Introduce factory-controlled creation of established stream connections. **Acceptance:** Shared inbound/outbound Connection implementation. **Proof:** PENDING.
- [ ] **U02.02** Unify local/peer address, TLS identity and negotiated protocol metadata. **Acceptance:** Real TCP/TLS/Unix metadata verified. **Proof:** PENDING.
- [ ] **U02.03** Define OPEN, HALF_CLOSED, DRAINING and CLOSED state contracts. **Acceptance:** Forbidden transition and double-close tests. **Proof:** PENDING.
- [ ] **U02.04** Unify buffered send/receive, watermarks and partial-write handling. **Acceptance:** Bounded memory under slow peers. **Proof:** PENDING.
- [ ] **U02.05** Define callback-to-Fiber ownership transfer and read watchers. **Acceptance:** No duplicate frames or stale callbacks. **Proof:** PENDING.
- [ ] **U02.06** Attach deadlines, timers and connection cleanup to owner scope. **Acceptance:** Cancellation closes exactly once. **Proof:** PENDING.
- [ ] **U02.07** Integrate platform socket options with explicit policy overrides. **Acceptance:** Correct TCP tuning and precedence. **Proof:** PENDING.
- [ ] **U02.08** Preserve inbound hot paths and raw/line/length-prefix framed behavior. **Acceptance:** Existing server tests and benchmarks pass. **Proof:** PENDING.
- [ ] **U02.09** Exercise many simultaneous opens/closes, EOF and worker shutdown. **Acceptance:** No FD, timer or buffer leak. **Proof:** PENDING.

**Batch tracker:** 0/9 accepted; 9 open. **Exit:** all evidence linked, required QA green, no unresolved critical regression.

### U03 — Async DNS and outbound TCP/Unix dialer

**Prerequisite:** U01,U02. **Batch QA:** targeted unit, fault and real endpoint tests; PHP8.4/8.5 stable/lowest PHPForge QA where relevant; CI evidence; tracker sync before proceeding.

- [ ] **U03.01** Define Dialer and DialOptions public endpoint and error contracts. **Acceptance:** Typed URI and invalid-address tests. **Proof:** PENDING.
- [ ] **U03.02** Implement nonblocking TCP connect with write readiness and error verification. **Acceptance:** Real IPv4/IPv6 TCP peer acceptance. **Proof:** PENDING.
- [ ] **U03.03** Implement Unix-domain outgoing connections. **Acceptance:** Real Unix peers and permission errors verified. **Proof:** PENDING.
- [ ] **U03.04** Implement genuinely off-loop A/AAAA DNS with bounded work. **Acceptance:** DNS delay cannot stall event-loop heartbeat. **Proof:** PENDING.
- [ ] **U03.05** Implement Happy Eyeballs address selection and staggered racing. **Acceptance:** Slow/failing address fallback without leaked sockets. **Proof:** PENDING.
- [ ] **U03.06** Support per-stage and overall deadlines, cancellation and cleanup. **Acceptance:** Late DNS/connect events ignored. **Proof:** PENDING.
- [ ] **U03.07** Limit concurrent pending dials and queued endpoints per scope/worker. **Acceptance:** Dial storm bounded RSS/FD. **Proof:** PENDING.
- [ ] **U03.08** Integrate with Fiber scope and callback style without duplication. **Acceptance:** Direct and coroutine clients use same connector. **Proof:** PENDING.
- [ ] **U03.09** Validate Swoole/OpenSwoole reactor support and no blocking fallback. **Acceptance:** Both host modes pass or report clear limits. **Proof:** PENDING.
- [ ] **U03.10** Benchmark successful overlap and contention versus sequential connects. **Acceptance:** Throughput, p99, CPU, RSS, errors recorded. **Proof:** PENDING.

**Batch tracker:** 0/10 accepted; 10 open. **Exit:** all evidence linked, required QA green, no unresolved critical regression.

### U04 — Unified TLS security and client/server handshakes

**Prerequisite:** U02,U03. **Batch QA:** targeted unit, fault and real endpoint tests; PHP8.4/8.5 stable/lowest PHPForge QA where relevant; CI evidence; tracker sync before proceeding.

- [ ] **U04.01** Separate client verification policy from server identity configuration. **Acceptance:** Secure peer/hostname checking defaults. **Proof:** PENDING.
- [ ] **U04.02** Implement genuinely asynchronous client TLS handshake. **Acceptance:** TLS1.2/1.3 read/write readiness proven. **Proof:** PENDING.
- [ ] **U04.03** Share correct TLS state machinery between inbound and outbound. **Acceptance:** Existing server ALPN tests preserved. **Proof:** PENDING.
- [ ] **U04.04** Implement SNI, ALPN, trust roots and hostname/SAN checks. **Acceptance:** Wrong/untrusted peer rejected. **Proof:** PENDING.
- [ ] **U04.05** Support mutual TLS identities with bounded chain validation. **Acceptance:** Positive and negative mTLS tests. **Proof:** PENDING.
- [ ] **U04.06** Implement handshake timeout, cancel, close-notify and truncation handling. **Acceptance:** Zero orphan TLS watchers or insecure downgrade. **Proof:** PENDING.
- [ ] **U04.07** Maintain optional OpenSSL and explicit TLS unavailable failure. **Acceptance:** Plain TCP still usable without ext-openssl. **Proof:** PENDING.
- [ ] **U04.08** Profile TLS latency and steady-state RPS/RSS versus 2.1.2. **Acceptance:** Matched result within strict performance limits. **Proof:** PENDING.

**Batch tracker:** 0/8 accepted; 8 open. **Exit:** all evidence linked, required QA green, no unresolved critical regression.

### U05 — UDP, outbound QUIC and unified datagram transport

**Prerequisite:** U02,U03,U04. **Batch QA:** targeted unit, fault and real endpoint tests; PHP8.4/8.5 stable/lowest PHPForge QA where relevant; CI evidence; tracker sync before proceeding.

- [ ] **U05.01** Unify inbound and outbound UDP ownership, message limits and metadata. **Acceptance:** IPv4/IPv6 datagram interoperability. **Proof:** PENDING.
- [ ] **U05.02** Implement connected and unconnected UDP client operations. **Acceptance:** Bounded send/receive and deadline tests. **Proof:** PENDING.
- [ ] **U05.03** Model UDP truncation, zero-length messages and packet-size ceilings. **Acceptance:** Exact datagram boundary semantics. **Proof:** PENDING.
- [ ] **U05.04** Implement outbound QUIC client connection and TLS validation. **Acceptance:** Independent QUIC server acceptance. **Proof:** PENDING.
- [ ] **U05.05** Support QUIC unidirectional/bidirectional streams and flow-control. **Acceptance:** Real reset/cancellation and sibling isolation tests. **Proof:** PENDING.
- [ ] **U05.06** Add negotiated QUIC datagram support and capability checks. **Acceptance:** Unsupported backend fails explicitly. **Proof:** PENDING.
- [ ] **U05.07** Define QUIC path/migration/rebinding/connection-ID policy. **Acceptance:** Independent migration or deliberate rejection verified. **Proof:** PENDING.
- [ ] **U05.08** Retain application 0-RTT disabled without proven replay policy. **Acceptance:** No unexpected early application dispatch. **Proof:** PENDING.
- [ ] **U05.09** Fault-test loss, reorder, cancellation storm and stalled peers. **Acceptance:** Safe bounded resources and protocol state. **Proof:** PENDING.
- [ ] **U05.10** Benchmark real QUIC poll/write budget and H3 transport costs. **Acceptance:** p99, RPS, CPU, RSS and packet metrics. **Proof:** PENDING.

**Batch tracker:** 0/10 accepted; 10 open. **Exit:** all evidence linked, required QA green, no unresolved critical regression.

### U06 — Structured concurrency, tasks, deadlines and scopes

**Prerequisite:** U01,U02,U03. **Batch QA:** targeted unit, fault and real endpoint tests; PHP8.4/8.5 stable/lowest PHPForge QA where relevant; CI evidence; tracker sync before proceeding.

- [ ] **U06.01** Unify Fiber tasks and network I/O under one owner scope. **Acceptance:** Pending dials cancelled at scope shutdown. **Proof:** PENDING.
- [ ] **U06.02** Propagate parent and child deadlines to DNS/TCP/TLS/protocol/files. **Acceptance:** No child outlives forbidden deadline. **Proof:** PENDING.
- [ ] **U06.03** Implement explicit transfer of pooled or worker-owned resources. **Acceptance:** No accidental request-to-request retention. **Proof:** PENDING.
- [ ] **U06.04** Resolve task completion versus cancellation races exactly once. **Acceptance:** No double-future completion or late callback. **Proof:** PENDING.
- [ ] **U06.05** Preserve TaskGroup, Channel, Mutex, Barrier and TaskLocal semantics. **Acceptance:** Existing concurrency correctness tests retained. **Proof:** PENDING.
- [ ] **U06.06** Bound scope tasks, waiters and queued network/filesystem operations. **Acceptance:** Hard quota and overload policy. **Proof:** PENDING.
- [ ] **U06.07** Support callback and coroutine paths through identical connection primitives. **Acceptance:** No duplicate I/O state machine. **Proof:** PENDING.
- [ ] **U06.08** Run live mixed coroutine, socket and timer endurance tests. **Acceptance:** Bounded scheduler lag and no FD/RSS leak. **Proof:** PENDING.

**Batch tracker:** 0/8 accepted; 8 open. **Exit:** all evidence linked, required QA green, no unresolved critical regression.

### U07 — HTTP/1.1, HTTP/2 and HTTP/3 clients

**Prerequisite:** U03,U04,U05,U06. **Batch QA:** targeted unit, fault and real endpoint tests; PHP8.4/8.5 stable/lowest PHPForge QA where relevant; CI evidence; tracker sync before proceeding.

- [ ] **U07.01** Define one streaming HTTP client request/response interface. **Acceptance:** Explicit H1/H2/H3 version and policy negotiation. **Proof:** PENDING.
- [ ] **U07.02** Implement HTTP/1.1 request writer and response parser. **Acceptance:** Chunked, keepalive, trailers and malformed-message acceptance. **Proof:** PENDING.
- [ ] **U07.03** Implement safe H1 connection reuse and streaming upload/download. **Acceptance:** No response bleed or unbounded buffering. **Proof:** PENDING.
- [ ] **U07.04** Implement HTTP/2 client preface, SETTINGS and HPACK. **Acceptance:** Independent H2 reference-server conformance. **Proof:** PENDING.
- [ ] **U07.05** Implement H2 multiplex, flow control, RST_STREAM and GOAWAY. **Acceptance:** Slow-peer and cancellation isolation. **Proof:** PENDING.
- [ ] **U07.06** Implement HTTP/3 client control streams, QPACK and QUIC ownership. **Acceptance:** Independent aioquic/ngtcp2 server conformance. **Proof:** PENDING.
- [ ] **U07.07** Implement H3 blocking/unblocking and reset/cancel handling. **Acceptance:** Concurrent sibling requests remain correct. **Proof:** PENDING.
- [ ] **U07.08** Secure HTTP version fallback and TLS/ALPN identity. **Acceptance:** No unintended plaintext fallback. **Proof:** PENDING.
- [ ] **U07.09** Add bounded redirect, credentials, cookies and retry policy. **Acceptance:** No unsafe replay or cross-origin credential leak. **Proof:** PENDING.
- [ ] **U07.10** Test malformed HTTP servers and large bidirectional streams. **Acceptance:** Fuzz plus hash-correct responses and resource budgets. **Proof:** PENDING.
- [ ] **U07.11** Benchmark native H1/H2/H3 outbound clients against peers. **Acceptance:** Matched RPS, p99, CPU and RSS. **Proof:** PENDING.

**Batch tracker:** 0/11 accepted; 11 open. **Exit:** all evidence linked, required QA green, no unresolved critical regression.

### U08 — Existing H1/H2/H3 server consolidation

**Prerequisite:** U02,U04,U05,U06. **Batch QA:** targeted unit, fault and real endpoint tests; PHP8.4/8.5 stable/lowest PHPForge QA where relevant; CI evidence; tracker sync before proceeding.

- [ ] **U08.01** Port H1 parser and streaming writer to shared connection ownership. **Acceptance:** Published 2.1.2 server corpus retained. **Proof:** PENDING.
- [ ] **U08.02** Port H2 connection and response scheduler without parallel flow controllers. **Acceptance:** Multi-stream/GOAWAY correctness. **Proof:** PENDING.
- [ ] **U08.03** Port H3/QUIC server without duplicate session resource owners. **Acceptance:** QPACK/peer-reset real-client acceptance. **Proof:** PENDING.
- [ ] **U08.04** Profile HPACK integer, Huffman, validation and write churn. **Acceptance:** Old R4.02 quantified with before/after tests. **Proof:** PENDING.
- [ ] **U08.05** Profile QPACK dynamic blocking, varints and QUIC poll/write budgets. **Acceptance:** Old R4.04 quantified with independent workloads. **Proof:** PENDING.
- [ ] **U08.06** Preserve header/body/frame/security caps under unified budgets. **Acceptance:** Limits not weakened by optimization. **Proof:** PENDING.
- [ ] **U08.07** Keep stable FIXED adaptive scheduling until AUTO is certified. **Acceptance:** No unverified behavioral promotion. **Proof:** PENDING.
- [ ] **U08.08** Stress mixed H1/H2/H3, many streams, cancellation and slow receivers. **Acceptance:** Old R4.09 tested under rolling recycle. **Proof:** PENDING.
- [ ] **U08.09** Benchmark TLS/plaintext sizes, socket writes, syscalls and percentiles. **Acceptance:** Old R3.09 measured end to end. **Proof:** PENDING.
- [ ] **U08.10** Reverify H1 WebSocket upgrade and graceful close paths. **Acceptance:** Existing upgrade interoperability green. **Proof:** PENDING.

**Batch tracker:** 0/10 accepted; 10 open. **Exit:** all evidence linked, required QA green, no unresolved critical regression.

### U09 — WebSocket and WebTransport client/server

**Prerequisite:** U05,U07,U08. **Batch QA:** targeted unit, fault and real endpoint tests; PHP8.4/8.5 stable/lowest PHPForge QA where relevant; CI evidence; tracker sync before proceeding.

- [ ] **U09.01** Share WebSocket framing, masking, control frames and close semantics. **Acceptance:** RFC6455 fuzz and interoperability. **Proof:** PENDING.
- [ ] **U09.02** Implement outbound WebSocket client with TLS and subprotocols. **Acceptance:** Independent server connects and closes safely. **Proof:** PENDING.
- [ ] **U09.03** Unify WS heartbeat, fragmentation, backpressure and message limits. **Acceptance:** High connection counts bounded. **Proof:** PENDING.
- [ ] **U09.04** Validate H2/H3 extended CONNECT only if implemented and qualified. **Acceptance:** No unsupported version advertised. **Proof:** PENDING.
- [ ] **U09.05** Design WebTransport sessions, streams and datagram ownership. **Acceptance:** H3/session lifecycle and authority policy documented. **Proof:** PENDING.
- [ ] **U09.06** Implement WebTransport server over QUIC/H3. **Acceptance:** Independent WebTransport client conformance. **Proof:** PENDING.
- [ ] **U09.07** Implement WebTransport client over outbound QUIC/H3. **Acceptance:** Independent WebTransport server conformance. **Proof:** PENDING.
- [ ] **U09.08** Support uni/bidi streams, resets, datagrams and negotiated MTU. **Acceptance:** Per-session flow limits and cancellation tests. **Proof:** PENDING.
- [ ] **U09.09** Support session idle timeout, graceful drain and overload policy. **Acceptance:** No orphan QUIC streams on shutdown. **Proof:** PENDING.
- [ ] **U09.10** Run WT/WS hostile frame, slow-peer and throughput suites. **Acceptance:** Fuzz, RPS/p99, RSS and fairness gates. **Proof:** PENDING.

**Batch tracker:** 0/10 accepted; 10 open. **Exit:** all evidence linked, required QA green, no unresolved critical regression.

### U10 — Connection pooling, proxy negotiation and stream relay

**Prerequisite:** U03,U04,U06,U07. **Batch QA:** targeted unit, fault and real endpoint tests; PHP8.4/8.5 stable/lowest PHPForge QA where relevant; CI evidence; tracker sync before proceeding.

- [ ] **U10.01** Define pool isolation keys by endpoint, TLS, ALPN, proxy and scope. **Acceptance:** No cross-origin/tenant reuse. **Proof:** PENDING.
- [ ] **U10.02** Implement bounded pool, wait queue, timeout, eviction and health checks. **Acceptance:** No infinite waiter or stale socket. **Proof:** PENDING.
- [ ] **U10.03** Integrate HTTP/2 and HTTP/3 multiplex slots and GOAWAY draining. **Acceptance:** Correct concurrent request capacity. **Proof:** PENDING.
- [ ] **U10.04** Implement HTTP CONNECT proxy tunneling and authentication. **Acceptance:** Real proxied TLS interoperates. **Proof:** PENDING.
- [ ] **U10.05** Implement SOCKS5 TCP negotiation and remote/local DNS policy. **Acceptance:** Auth and DNS leak protections tested. **Proof:** PENDING.
- [ ] **U10.06** Verify nested TLS and original endpoint certificate identity. **Acceptance:** No proxy-induced verification downgrade. **Proof:** PENDING.
- [ ] **U10.07** Implement bounded bidirectional TCP/Unix stream relay. **Acceptance:** Large hash-valid transfer with backpressure. **Proof:** PENDING.
- [ ] **U10.08** Support half-close, cancellation, timeout and worker-drain cleanup. **Acceptance:** No deadlock or orphan relay task. **Proof:** PENDING.
- [ ] **U10.09** Measure pool hit rate, p99, CPU, FD and RSS versus direct dial. **Acceptance:** Useful gains evidenced under saturation. **Proof:** PENDING.

**Batch tracker:** 0/9 accepted; 9 open. **Exit:** all evidence linked, required QA green, no unresolved critical regression.

### U11 — Truly asynchronous filesystem and file streaming

**Prerequisite:** U01,U02,U06. **Batch QA:** targeted unit, fault and real endpoint tests; PHP8.4/8.5 stable/lowest PHPForge QA where relevant; CI evidence; tracker sync before proceeding.

- [ ] **U11.01** Define async file API for open, read, write, stat, rename and unlink. **Acceptance:** Portable contract and errors. **Proof:** PENDING.
- [ ] **U11.02** Implement bounded executor backend for blocking filesystem calls. **Acceptance:** Slow disk operation never freezes event loop. **Proof:** PENDING.
- [ ] **U11.03** Use native accelerated backend only when available and proven. **Acceptance:** No mandatory extension or fake async. **Proof:** PENDING.
- [ ] **U11.04** Implement partial/offest writes and large binary file reads. **Acceptance:** Correct hash and offset semantics. **Proof:** PENDING.
- [ ] **U11.05** Stream file to/from network with low-memory backpressure. **Acceptance:** Slow network peer cannot buffer entire file. **Proof:** PENDING.
- [ ] **U11.06** Implement cancellation and deadlines for queued/active file jobs. **Acceptance:** Exactly-once terminal file result. **Proof:** PENDING.
- [ ] **U11.07** Close file handles on scope, request and worker shutdown. **Acceptance:** No FD leak after cancellation. **Proof:** PENDING.
- [ ] **U11.08** Define atomic replace/fsync guarantees and OS limitations. **Acceptance:** Crash-safety assumptions documented and tested. **Proof:** PENDING.
- [ ] **U11.09** Check symlink traversal, permissions and path policy. **Acceptance:** Hostile filesystem operations rejected. **Proof:** PENDING.
- [ ] **U11.10** Benchmark disk throughput, loop ticks, worker load and memory. **Acceptance:** Measured improvement with bounded overhead. **Proof:** PENDING.

**Batch tracker:** 0/10 accepted; 10 open. **Exit:** all evidence linked, required QA green, no unresolved critical regression.

### U12 — Native supervisor and adaptive worker autoscaling

**Prerequisite:** U01,U02,U06. **Batch QA:** targeted unit, fault and real endpoint tests; PHP8.4/8.5 stable/lowest PHPForge QA where relevant; CI evidence; tracker sync before proceeding.

- [ ] **U12.01** Revalidate worker crash restart, backoff and generation isolation. **Acceptance:** Historical 2.2 fixtures pass under 3.0. **Proof:** PENDING.
- [ ] **U12.02** Guarantee fork-safe listener, outbound pool, QUIC and executor ownership. **Acceptance:** No inherited incorrect resource ownership. **Proof:** PENDING.
- [ ] **U12.03** Define autoscale signals, bounds and operator overrides. **Acceptance:** CPU/queue/RPS/p99 policy clearly specified. **Proof:** PENDING.
- [ ] **U12.04** Implement cooldown, hysteresis, scale-out and graceful scale-in. **Acceptance:** No oscillation or admitted request loss. **Proof:** PENDING.
- [ ] **U12.05** Test SIGKILL, restart, reload, recycle and stop during H1/H2/H3/TCP/UDP traffic. **Acceptance:** Old R6.02 full real-peer acceptance. **Proof:** PENDING.
- [ ] **U12.06** Drain pending dials, sessions, pools, coroutines and file executor jobs. **Acceptance:** No terminal watcher or FD leak. **Proof:** PENDING.
- [ ] **U12.07** Respect host-owned worker pools and portable single-process limits. **Acceptance:** No false autoscaling promise for FPM/RoadRunner. **Proof:** PENDING.
- [ ] **U12.08** Fault inject crash loops, signals and concurrent scaling/reloading. **Acceptance:** Bounded restart count and shutdown behavior. **Proof:** PENDING.
- [ ] **U12.09** Run sustained autoscale stress and process-tree resource sampling. **Acceptance:** Fairness, p99, CPU and RSS accepted. **Proof:** PENDING.

**Batch tracker:** 0/9 accepted; 9 open. **Exit:** all evidence linked, required QA green, no unresolved critical regression.

### U13 — Real host and optional extension compatibility

**Prerequisite:** U01,U03,U04,U06,U12. **Batch QA:** targeted unit, fault and real endpoint tests; PHP8.4/8.5 stable/lowest PHPForge QA where relevant; CI evidence; tracker sync before proceeding.

- [ ] **U13.01** Verify native portable Select and native prefork execution. **Acceptance:** PHP8.4/8.5 real protocol/file tests. **Proof:** PENDING.
- [ ] **U13.02** Verify native ext-event remains optional and equivalent. **Acceptance:** Same correctness gates on both loops. **Proof:** PENDING.
- [ ] **U13.03** Verify Swoole and OpenSwoole actual hosting and outgoing I/O. **Acceptance:** One owned reactor per process. **Proof:** PENDING.
- [ ] **U13.04** Verify real RoadRunner worker lifecycle and pooled sockets/files. **Acceptance:** No leak or cross-request state. **Proof:** PENDING.
- [ ] **U13.05** Verify real FrankenPHP worker and host-mode differences. **Acceptance:** Repeated request scope isolation. **Proof:** PENDING.
- [ ] **U13.06** Verify real PHP-FPM request-scoped operations and cleanup. **Acceptance:** No unsupported persistent resource assumptions. **Proof:** PENDING.
- [ ] **U13.07** Test optional TLS, sockets, QUIC, PCNTL/POSIX absent/present. **Acceptance:** Explicit capability failure without Composer break. **Proof:** PENDING.
- [ ] **U13.08** Test standalone Composer archives PHP8.4/8.5 stable/lowest. **Acceptance:** Package works without external Infocyph projects. **Proof:** PENDING.
- [ ] **U13.09** Publish truthful per-host features and limitations matrix. **Acceptance:** No false universal asynchronous guarantees. **Proof:** PENDING.

**Batch tracker:** 0/9 accepted; 9 open. **Exit:** all evidence linked, required QA green, no unresolved critical regression.

### U14 — Security, fuzzing and adversarial resilience

**Prerequisite:** U03-U13. **Batch QA:** targeted unit, fault and real endpoint tests; PHP8.4/8.5 stable/lowest PHPForge QA where relevant; CI evidence; tracker sync before proceeding.

- [ ] **U14.01** Apply outbound SSRF policy to DNS results and connected address. **Acceptance:** No address swap or rebinding bypass. **Proof:** PENDING.
- [ ] **U14.02** Verify TLS certificate/hostname/mTLS, ALPN and proxy identity. **Acceptance:** Negative trust-store fixtures fail closed. **Proof:** PENDING.
- [ ] **U14.03** Fuzz HTTP1/H2/H3 and HPACK/QPACK/QUIC protocol state. **Acceptance:** Malformed frames preserve sibling sessions. **Proof:** PENDING.
- [ ] **U14.04** Fuzz WebSocket/WebTransport datagrams and stream cancellation. **Acceptance:** Hard message/session budgets remain enforced. **Proof:** PENDING.
- [ ] **U14.05** Test proxy credentials and redirection/pool isolation. **Acceptance:** No cross-origin secret exposure. **Proof:** PENDING.
- [ ] **U14.06** Audit async filesystem symlink/TOCTOU and sandbox access. **Acceptance:** Path/permission attack suite green. **Proof:** PENDING.
- [ ] **U14.07** Stress dial, DNS, TLS, pool and executor admission quotas. **Acceptance:** Memory/FD/error rates remain bounded. **Proof:** PENDING.
- [ ] **U14.08** Run phpforge security, analyzers and dependency audit. **Acceptance:** Zero unresolved release-blocking findings. **Proof:** PENDING.

**Batch tracker:** 0/8 accepted; 8 open. **Exit:** all evidence linked, required QA green, no unresolved critical regression.

### U15 — Performance attribution, regressions and soak

**Prerequisite:** U02-U14. **Batch QA:** targeted unit, fault and real endpoint tests; PHP8.4/8.5 stable/lowest PHPForge QA where relevant; CI evidence; tracker sync before proceeding.

- [ ] **U15.01** Attribute real cold and warmed handler, context, loop and syscall overhead. **Acceptance:** Old R0.06 traced with hot-path evidence. **Proof:** PENDING.
- [ ] **U15.02** Establish warmed successful throughput concurrency curves with load generator headroom. **Acceptance:** Old R0.07 p50/p95/p99/CPU/RSS/FD. **Proof:** PENDING.
- [ ] **U15.03** Compare native/hosted tracing enabled versus disabled. **Acceptance:** Old R1.08 overhead bounded. **Proof:** PENDING.
- [ ] **U15.04** Profile H1 plaintext/TLS, H2 HPACK and H3 QPACK/QUIC real hot paths. **Acceptance:** Old R3.09/R4.02/R4.04 measured. **Proof:** PENDING.
- [ ] **U15.05** Stress mixed transport slow-peer and cancellation under recycle. **Acceptance:** Old R4.09 accepted with no hidden failures. **Proof:** PENDING.
- [ ] **U15.06** Compare sequential and concurrent outbound I/O and connection pooling. **Acceptance:** Old R5.08 measured benefit. **Proof:** PENDING.
- [ ] **U15.07** Benchmark H1/H2/H3, WS/WT clients and servers with real counterparts. **Acceptance:** CPU/RSS/p99 and errors captured. **Proof:** PENDING.
- [ ] **U15.08** Benchmark file executor and autoscale under mixed network load. **Acceptance:** No loop starvation or excessive overhead. **Proof:** PENDING.
- [ ] **U15.09** Run exact baseline 2.1.2 versus 3.0 five paired 180s comparisons. **Acceptance:** Both variance CV less than 2.5 percent and strict p99/RSS/RPS gates. **Proof:** PENDING.
- [ ] **U15.10** Run full 1800-second process-tree and GC/OPcache/FD soak. **Acceptance:** No errors, unbounded growth or stale workers. **Proof:** PENDING.

**Batch tracker:** 0/10 accepted; 10 open. **Exit:** all evidence linked, required QA green, no unresolved critical regression.

### U16 — Read the Docs-compatible Sphinx documentation and full feature catalog

**Prerequisite:** U00-U15. **Batch QA:** targeted unit, fault and real endpoint tests; PHP8.4/8.5 stable/lowest PHPForge QA where relevant; CI evidence; tracker sync before proceeding.

- [ ] **U16.01** Set up docs/sphinx with Sphinx conf.py and pinned documentation dependencies. **Acceptance:** Reproducible warning-free HTML build. **Proof:** PENDING.
- [ ] **U16.02** Add repository root .readthedocs.yaml v2 and hosted docs build config. **Acceptance:** Read the Docs preview builds correctly. **Proof:** PENDING.
- [ ] **U16.03** Choose MyST Markdown and RST navigation strategy to reuse appropriate existing docs. **Acceptance:** No duplicate or broken pages. **Proof:** PENDING.
- [ ] **U16.04** Create complete versioned public feature catalog. **Acceptance:** Every feature annotated by supported host, transport and extension. **Proof:** PENDING.
- [ ] **U16.05** Document TCP/TLS/Unix/UDP/QUIC inbound/outbound and DNS/Happy Eyeballs. **Acceptance:** Runnable client/server examples and errors. **Proof:** PENDING.
- [ ] **U16.06** Document HTTP1/2/3 client/server and WebSocket/WebTransport. **Acceptance:** Streaming, header limits, cancellation and conformance examples. **Proof:** PENDING.
- [ ] **U16.07** Document pooling, CONNECT/SOCKS5, relay, TLS and authentication policies. **Acceptance:** Security, credentials, transport lifecycle cases. **Proof:** PENDING.
- [ ] **U16.08** Document Fiber tasks, event loops, deadlines and ownership. **Acceptance:** Scope and async resource lifecycle diagrams/examples. **Proof:** PENDING.
- [ ] **U16.09** Document async filesystem executor, safe file I/O and streaming. **Acceptance:** No-hidden-blocking details, quotas and portability. **Proof:** PENDING.
- [ ] **U16.10** Document native/portable supervision, autoscaling, FPM/FrankenPHP/RoadRunner/Swoole. **Acceptance:** Accurate per-host features and constraints. **Proof:** PENDING.
- [ ] **U16.11** Generate complete source-linked PHP API reference and error catalog. **Acceptance:** Every shipped public symbol documented. **Proof:** PENDING.
- [ ] **U16.12** Write full 2.1.2 to 3.0 breaking migration guide and before/after snippets. **Acceptance:** All intentional API removals/migrations covered. **Proof:** PENDING.
- [ ] **U16.13** Write end-to-end tutorials, recipes, deployment and troubleshooting guides. **Acceptance:** Examples execute in CI. **Proof:** PENDING.
- [ ] **U16.14** Document performance tuning, benchmarks, metrics, threat model and QA provenance. **Acceptance:** All quantitative claims link to reproducible source. **Proof:** PENDING.
- [ ] **U16.15** Enforce sphinx-build -n -W --keep-going -b html plus links and code snippet validation. **Acceptance:** CI fails on unresolved refs or invalid examples. **Proof:** PENDING.
- [ ] **U16.16** Verify Read the Docs navigation, search, versioning and complete feature index. **Acceptance:** Hosted preview passes and 100 percent feature coverage. **Proof:** PENDING.

**Batch tracker:** 0/16 accepted; 16 open. **Exit:** all evidence linked, required QA green, no unresolved critical regression.

### U17 — Final certification, risk signoff and maintainer handoff

**Prerequisite:** U00-U16. **Batch QA:** targeted unit, fault and real endpoint tests; PHP8.4/8.5 stable/lowest PHPForge QA where relevant; CI evidence; tracker sync before proceeding.

- [ ] **U17.01** Reconcile 2.2 carry-forward with final 3.0 public feature inventory. **Acceptance:** No applicable old or new task unexplained. **Proof:** PENDING.
- [ ] **U17.02** Run all PHP8.4/8.5 PHPForge QA, analysis and security checks. **Acceptance:** Zero release-blocking issues. **Proof:** PENDING.
- [ ] **U17.03** Run real native, hosts and independent H1/H2/H3/WS/WT/QUIC acceptance. **Acceptance:** Every supported mode certified at frozen source. **Proof:** PENDING.
- [ ] **U17.04** Verify Sphinx/Read the Docs full docs, API and runnable example coverage. **Acceptance:** Zero warnings, broken links or undocumented features. **Proof:** PENDING.
- [ ] **U17.05** Validate standalone Composer packaging and optional extension matrix. **Acceptance:** No external repo integration gates. **Proof:** PENDING.
- [ ] **U17.06** Run exact frozen SHA 2.1.2 matched 5x180s and 30m process-tree soak. **Acceptance:** Strict variance, p99, successful RPS and RSS budgets satisfied. **Proof:** PENDING.
- [ ] **U17.07** Audit final fuzz results, security, benchmarks and migration risks. **Acceptance:** Maintainer-approved risk register. **Proof:** PENDING.
- [ ] **U17.08** Synchronize final source audit, QA run IDs, PR and canonical tracker. **Acceptance:** All evidence pinned to final immutable SHA. **Proof:** PENDING.
- [ ] **U17.09** Keep PR draft, unmerged, untagged and unpublished until explicit direction. **Acceptance:** READY FOR MAINTAINER RELEASE only on full pass. **Proof:** PENDING.

**Batch tracker:** 0/9 accepted; 9 open. **Exit:** all evidence linked, required QA green, no unresolved critical regression.

## Complete leftover carry-forward (20 / 20)

The [historical 2.2 plan](https://github.com/infocyph/Runwire/blob/7afd11e21ec4e64c610885f5f6ba42311b61c4d0/docs/plans/runwire-2.2-runtime-improvement-plan.md) has **95 historical IDs, 75 previously checked, 20 unchecked**. It remains accessible at its immutable Git commit but is not in the active working tree. The 20 unchecked include seven R5.02-R5.08 dialer items that were deferred under 2.2, but **are mandatory in 3.0**. Prior checked work is regression coverage only; 3.0 starts at zero accepted tasks.

| Old unfinished 2.2 ID | 3.0 acceptance task(s) | Status |
| --- | --- | --- |
| R0.06 | U15.01 | OPEN; retest against 3.0 |
| R0.07 | U15.02 | OPEN; retest against 3.0 |
| R1.08 | U15.03 | OPEN; retest against 3.0 |
| R3.09 | U08.09 + U15.04 | OPEN; retest against 3.0 |
| R4.02 | U08.04 + U15.04 | OPEN; retest against 3.0 |
| R4.04 | U08.05 + U15.04 | OPEN; retest against 3.0 |
| R4.09 | U08.08 + U15.05 | OPEN; retest against 3.0 |
| R5.02 | U03.01 | OPEN; retest against 3.0 |
| R5.03 | U02.06 + U06.03 | OPEN; retest against 3.0 |
| R5.04 | U02.04 | OPEN; retest against 3.0 |
| R5.05 | U04.02 + U04.06 | OPEN; retest against 3.0 |
| R5.06 | U03.08 | OPEN; retest against 3.0 |
| R5.07 | U03.09 + U13.03 | OPEN; retest against 3.0 |
| R5.08 | U03.10 + U15.06 | OPEN; retest against 3.0 |
| R6.02 | U12.05 | OPEN; retest against 3.0 |
| R8.05 | U17.06 | OPEN; retest against 3.0 |
| R8.10 | U17.07 | OPEN; retest against 3.0 |
| R9.06 | U17.08 | OPEN; retest against 3.0 |
| R9.07 | U17.07 | OPEN; retest against 3.0 |
| R9.09 | U17.09 | OPEN; retest against 3.0 |

## Sphinx / Read the Docs complete feature documentation index

All of these pages are **required deliverables** inside the proposed docs/sphinx documentation tree, not existing published documentation.

| Feature division | Docs page hierarchy | Mandatory content |
| --- | --- | --- |
| Installation/requirements | getting-started/, reference/capabilities/ | PHP, OS, extension, host and fallback matrix |
| All public APIs and errors | api/, reference/errors/ | Generated PHP source signatures and typed errors |
| Breaking migration | migration/2.1-to-3.0.md | Before/after API, removal, behavior changes |
| Loops, Fiber and scope | concurrency/ | Task, watcher, cancellation, lifetime, limits |
| TCP/TLS/Unix/UDP/QUIC | network/ | Bidirectional examples, deadlines and security |
| DNS and dialer | network/dialer.md, network/dns.md | Racing, IPv4/6, connect and failures |
| HTTP/1.1, 2, 3 | protocols/http/ | Full client/server streaming, headers and flow control |
| WebSocket / WebTransport | protocols/websocket.md, protocols/webtransport.md | Sessions, frames, streams, datagrams |
| Pools/proxies/relay | network/pool.md, network/proxy.md, network/relay.md | CONNECT/SOCKS5, credentials, backpressure |
| Filesystem and executor | io/filesystem.md | Genuine async, quotas, cancellation, platform limits |
| Runtime and autoscaling | runtime/, deployment/ | Native prefork/portable and hosted runtimes |
| Security and performance | security/, operations/ | TLS, SSRF, observability, p99/RSS, threat models |
| Guides/tutorials/recipes | tutorials/, examples/, troubleshooting/ | Runnable real application examples |

**Docs technical gate:** docs/sphinx/conf.py, pinned doc requirements, .readthedocs.yaml v2, MyST Markdown and RST support, API extraction from PHP, Sphinx warning-as-error HTML build with strict refs, link checks, runnable snippets, hosted Read the Docs preview, versioned navigation and **100% shipped feature documentation coverage**. Update documentation with each implemented feature batch; U16 verifies full completeness.

## Required QA matrix and final release rules

| Gate | Requirement |
| --- | --- |
| PHP | PHP 8.4/8.5, Composer stable/lowest QA, PHPStan/Psalm, static security |
| Event loops | Portable Select, ext-event and compatible host-owned reactors |
| Hosts | Native prefork/portable, real FPM/FrankenPHP/RoadRunner/Swoole/OpenSwoole |
| Transport | Independent client/server TCP/TLS/Unix/UDP/QUIC, IPv4/IPv6 |
| Protocol | Independent H1/H2/H3/WS/WT client and server, malformed and cancel storms |
| Async work | Actual nonblocking DNS and filesystem, executor quota/stop/error coverage |
| Worker lifecycle | Crash/backoff, hot reload, recycle, autoscale and active stop |
| Performance | 2.1.2 matched baseline + CV, p99/RSS, 1800-second process-tree/GC/OPcache/FD soak |
| Docs | Read the Docs Sphinx preview, full feature/API reference, strict build, runnable examples |
| Release | No unresolved blocker, complete migration, QA pinned to immutable frozen SHA |

**Historical QA transparency:** previous seven standard workflows passed on 2.2 code 8b125bab. The separate [Release Certification #39891900634](https://github.com/infocyph/Runwire/actions/runs/39891900634) **FAILED overall** due to above-ceiling benchmark variance; its individual 30-minute soak and other four jobs passed. This is not 3.0 certification. Earlier H1 passing certificates are likewise historical.

## Progress journal

| Date | Batch | Commit/CI | Accepted tasks | Failed checks | Tracker | Next |
| --- | --- | --- | --- | --- | --- | --- |
| 2026-10-23 | Scope initialization | Planning only | None | 3.0 QA not run; historical 2.2 certification inconclusive | 0/175 | U00 API and architecture |
| 2026-10-23 | U00 source/API audit, historical reconciliation and architecture contracts | [Source Audit #39904006992](https://github.com/infocyph/Runwire/actions/runs/39904006992), [PHPForge #39904007423](https://github.com/infocyph/Runwire/actions/runs/39904007423) | U00.01/.02/.03/.06/.07/.08/.10 | Three U00 engineering/benchmark gates open; H3 and extended benchmark workflow conclusions checked separately | 7/175 | U00.04/.05/.09 |

**Release verdict:** NOT READY. Keep PR #9 draft, branch unchanged, no merge/tag/publish without explicit maintainer authorization.
