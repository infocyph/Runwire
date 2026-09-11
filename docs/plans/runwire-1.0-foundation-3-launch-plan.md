# Runwire 1.0 — Process, Supervisor & Network Runtime / Foundation 3 Launch Plan

## Status

Target release: **Runwire 1.0**

Primary launch consumer: **Foundation 3**

Primary HTTP integration: **Webrick**

Primary messaging integration: **Omnibus**

PHP baseline: **^8.4**

Reference server/runtime: `walkor/workerman` — architectural and benchmark reference only; Runwire must not depend on or clone Workerman.

Priority:

> correctness → process isolation/ownership → network safety/backpressure → protocol correctness → persistent-runtime safety → performance → scalability → ergonomics

Runwire is the low-level Infocyph process + supervisor + event-loop + network runtime. It absorbs the earlier ProcessGuard scope and provides reusable process execution, worker supervision, native networking, and a version-neutral HTTP transport for Foundation/Webrick while remaining framework agnostic.

Package identity:

```text
Composer:  infocyph/runwire
Namespace: Infocyph\Runwire
```

Tagline:

> A high-performance process and network runtime for PHP.

---

# Implementation tracker

Last updated: **2026-09-11**

| Milestone | Status | Evidence / next gate |
| --- | --- | --- |
| Package scaffold + runtime/OPcache contracts | ✅ Landed | runtime options/capability model present |
| Loop contract + bounded `SelectLoop` | ✅ Landed | select/timer/deferred watcher implementation + tests |
| Prefork supervisor + worker lifecycle/generations | ✅ Landed | restart/reload/recycle/reaping/control plane implemented |
| Structured `ProcessRunner` + bounded concurrent pipe I/O | ✅ Landed | structured argv/process lifecycle + output/time bounds |
| TCP/TLS/Unix/UDP + connection lifecycle/backpressure | ✅ Landed | native stream/datagram listeners + pressure limits |
| Generic stream/datagram runtime + framing codecs | ✅ Landed | raw/line/length-prefixed codec/runtime workers |
| Version-neutral HTTP transport | ✅ Landed | `HttpRequest`, body and response-writer contracts |
| HTTP/1.1 engine | ✅ Landed | framing, body streaming, limits, keep-alive, backpressure tests |
| HTTP/2 frame/stream/HPACK/flow-control engine | ✅ Landed | multiplexing, HPACK, scheduler, flow control, abuse tests |
| Native TLS ALPN HTTP/1.1 + HTTP/2 dispatch | ✅ Landed | `h2` / `http/1.1` native dispatch |
| HTTP/3 public capability surface | ✅ Landed | `ProtocolVersion::HTTP_3`, QUIC capability/ownership reporting |
| HTTP/3 framing + full bounded QPACK core | 🔄 Active | `53cc62e`; protocol QA/static-analysis and remaining hardening next |
| Native QUIC v1 + TLS 1.3 HTTP/3 worker | ⬜ Pending | wire `h3`, control/QPACK streams, request streams, drain/error mapping |
| HTTP/1.1 ↔ HTTP/2 ↔ HTTP/3 semantic parity | ⬜ Pending | same version-neutral application handler semantics |
| HTTP/3 abuse/fault/interoperability acceptance | ⬜ Pending | QUIC/QPACK stream churn, blocked streams, malformed frames, bounded memory |
| CI QUIC extension-present + extension-absent lanes | 🔄 Active | workflow update required before more HTTP/3 runtime work |
| Webrick native Runwire adapter contract | ⬜ Pending | begin only after Runwire transport gates are stable |
| Host-driver execution: FPM/FrankenPHP/Swoole/RoadRunner | ⬜ Pending | advertised drivers must become executable, not capability-only |
| Foundation native server + persistent driver integration | ⬜ Pending | after Runwire 1.0 runtime gates |
| Omnibus/boundary integrations + aggregate soak/QA/benchmarks | ⬜ Pending | after Runwire-only gates |
| Runwire 1.0 final release acceptance | ⬜ Pending | Sections 41 and 43 are authoritative |

Tracker semantics:

- **Landed** means the implementation slice is committed and has focused validation; it does not mean every release/soak/integration gate for that area is complete.
- **Active** is the current implementation slice.
- **Pending** has not yet passed substantive implementation/acceptance.
- Runwire itself must be finalized before switching implementation work into Foundation/Webrick/Omnibus.
- Sections **41** and **43** are the authoritative release-completion gates.

---

# 1. Core ownership

Runwire owns generic runtime mechanics that remain meaningful without Foundation, Webrick, Omnibus, ReqShield or Pathwise.

Runwire owns:

- structured child-process execution;
- prefork worker supervision and generations;
- PID/reaping/signal/restart/reload mechanics;
- event-loop/timer/deferred/watcher primitives;
- TCP, UDP and Unix-domain listener mechanics;
- TLS transport;
- QUIC transport integration for native HTTP/3;
- non-blocking stream lifecycle and backpressure;
- connection/stream/time/resource limits;
- generic framing/codec contracts;
- native HTTP/1.1 wire parsing/serialization;
- native HTTP/2 frame/stream/HPACK/flow-control state;
- native HTTP/3 frame/stream/QPACK state over QUIC;
- TLS ALPN for `h2` / `http/1.1`;
- QUIC ALPN `h3` and HTTP/3 connection startup;
- protocol error/drain/close handling;
- status/control/lifecycle events;
- runtime capability detection including `supports_http3`, `owns_http3_wire`, and QUIC availability;
- integration hooks for stronger OS process isolation.

Runwire does not own:

- Foundation DI/application semantics;
- Webrick routing/middleware/controllers;
- application authentication/session/business logic;
- request validation;
- database/cache creation;
- queue/message semantics;
- storage/upload policy;
- user/plugin authorization;
- cryptography primitives;
- arbitrary uploaded PHP execution;
- a claim that PHP-level process restrictions are a security sandbox.

---

# 2. Dependency direction

```text
                    Foundation
                  /     |      \
                 /      |       \
             Webrick  Omnibus   Runwire direct APIs
                |        |
                +--------+
                    |
                 Runwire
                    |
                 PHP / OS
```

Hard rules:

- Runwire must not require Foundation, Webrick, Omnibus, ReqShield, Pathwise, InterMix, DBLayer or CacheLayer.
- PHPForge remains development-only.
- QUIC support must remain an optional runtime capability at Composer/package installation time.
- Selecting native HTTP/3 must fail fast when the required QUIC capability is unavailable.
- Webrick/Foundation consume the version-neutral HTTP contract, not HTTP/1/2/3 internals.

---

# 3. Runtime baseline and extensions

Baseline:

```text
PHP: ^8.4
PHPForge: dev-main@dev (development only)
```

Core process/runtime extensions:

- `ext-pcntl` — Unix fork/signal/wait baseline;
- `ext-posix` — Unix identity/signalling helpers;
- `ext-openssl` — TCP/TLS support;
- `ext-sockets` — enhanced socket support where used;
- `ext-event` — optional faster event backend;
- pure PHP `stream_select()` remains the portable native loop baseline.

HTTP/3/QUIC capability:

- Runwire 1.0 supports native HTTP/3 through an optional mature QUIC extension/engine adapter;
- the initial supported CI engine is `mikepultz/php-quic` (`ext-quic` / `Quic\*` API), installed independently of Composer application dependencies;
- QUIC requires TLS 1.3-capable underlying crypto support supplied by the QUIC engine;
- core installation must still work when `ext-quic` is absent;
- `RuntimeEnvironmentProbe` must distinguish QUIC unavailable from QUIC available;
- HTTP/3 capability reporting must never claim wire ownership merely because another host/proxy supports HTTP/3 upstream.

CI must prove both:

```text
normal PHPForge matrix: QUIC absent is valid
HTTP/3 integration matrix: QUIC extension present and native HTTP/3 tests execute
```

Do not make `ext-event` or `ext-quic` mandatory for ordinary Runwire installation.

---

# 4. Lifetime model

```text
master/runtime lifetime
    |
    +-- listener lifetime
    |      |
    |      +-- TCP/TLS connection lifetime
    |      |      ├-- HTTP/1.1 requests
    |      |      └-- HTTP/2 streams
    |      |
    |      +-- QUIC connection lifetime
    |             ├-- HTTP/3 control streams
    |             ├-- QPACK encoder/decoder streams
    |             └-- HTTP/3 request streams
    |
    +-- worker-process lifetime
    |      └-- event-loop/runtime-engine lifetime
    |
    +-- supervised child-process lifetime
```

A Runwire worker/QUIC connection/TCP connection is never an application request scope. Foundation/Webrick must create fresh logical execution state for each request or stream.

---

# 5. Configuration topology

Runtime configuration follows:

```text
construct/configure → validate → freeze → run
```

Requirements:

- topology is instance-owned;
- configuration becomes immutable before serving traffic;
- static normalization does not repeat in hot paths;
- one process can create independent runtime instances for tests;
- native TCP/TLS and UDP/QUIC ownership is explicit;
- HTTP/3 listener configuration must validate certificate/key, QUIC engine, ALPN and limits before advertising readiness;
- no process-global mutable protocol tables.

---

# 6. Event loop

Required event-loop capabilities:

- readable/writable watcher;
- one-shot/repeating timer;
- deferred callback;
- cancellation;
- run/stop;
- monotonic timing.

`SelectLoop` remains the baseline. `ext-event` may provide an adapter with behavioral parity.

HTTP/3 integration must not busy-poll QUIC. The QUIC adapter must expose bounded polling/timer integration with the active Runwire loop/worker strategy. A QUIC engine that requires its own host loop must be isolated behind the transport adapter rather than leaking host calls through HTTP/3 classes.

---

# 7. Network transport layer

Runwire 1.0 supports:

- TCP;
- UDP;
- Unix-domain sockets where supported;
- TLS over TCP;
- QUIC v1 over UDP for HTTP/3;
- IPv4/IPv6;
- graceful listener drain/close;
- bounded connection counts and buffers.

Native prefork model:

```text
master validates/binds supported shared resources
        ↓
forks trusted workers
        ↓
workers own request/connection processing
```

QUIC listener ownership must be documented and implemented according to the selected engine's fork/socket safety. Do not assume TCP descriptor-inheritance semantics automatically apply to QUIC engine objects. If the QUIC engine is not fork-safe, initialize/bind it in the worker according to an explicit deterministic strategy.

---

# 8. Connection, stream and backpressure layer

TCP stream connections require bounded receive/send buffers, high/low watermarks, pause/resume, graceful close, idle/lifetime timeouts and byte counters.

HTTP/2 and HTTP/3 additionally require per-stream and aggregate connection bounds.

HTTP/3/QUIC requirements:

- peer/local QUIC connection metadata;
- bounded bidirectional and unidirectional stream counts;
- bounded per-stream request/response buffering;
- aggregate QUIC connection buffering ceiling;
- QUIC transport flow-control credit and HTTP application backpressure compose without unbounded shadow queues;
- STOP_SENDING / RESET_STREAM are propagated to application/body cleanup;
- connection termination cleans all request/control/QPACK stream state exactly once;
- slow QUIC peers cannot consume unlimited worker memory.

---

# 9. Overload policy

Release-blocking backpressure cases:

```text
client sends faster than handler consumes
handler produces faster than transport writes
TCP connection ceiling reached
HTTP/2 stream ceiling reached
QUIC connection/HTTP/3 stream ceiling reached
QPACK blocked-stream budget reached
worker memory/output budget reached
```

Runwire must fail predictably rather than permit process-wide OOM or unbounded work amplification.

---

# 10. Native HTTP stack: HTTP/1.1 + HTTP/2 + HTTP/3

Runwire 1.0 ships all three native wire generations as first-class release requirements:

```text
HTTP/1.1  REQUIRED
HTTP/2    REQUIRED
HTTP/3    REQUIRED
```

Shared application contract:

```text
HTTP/1.1 request ───────────┐
HTTP/2 request stream ──────┼─> Runwire HttpRequest / ResponseWriterInterface ─> Webrick
HTTP/3 request stream ──────┘
```

Application semantics must not depend on transport version.

## 10.1 Standards baseline

- HTTP semantics: RFC 9110;
- HTTP/1.1: RFC 9112;
- HTTP/2: RFC 9113;
- HPACK: RFC 7541;
- TLS ALPN: RFC 7301;
- HTTP/3: RFC 9114;
- QUIC transport: RFC 9000;
- QUIC TLS: RFC 9001;
- QUIC loss detection/congestion control supplied by the selected mature QUIC engine according to RFC 9002 or compatible implementation;
- QPACK: RFC 9204;
- extensible HTTP priorities: RFC 9218 where consumed.

HTTP/3 is **not** HTTP/2 framing over UDP. Runwire HTTP/3 code operates over QUIC streams supplied by the QUIC adapter.

## 10.2 HTTP/1.1 acceptance

Keep existing production requirements:

- strict request-line/header parsing;
- duplicate/header semantics;
- Content-Length/chunked framing;
- smuggling ambiguity rejection;
- slow-header/body deadlines;
- body/header count/byte limits;
- keep-alive lifecycle;
- fixed/chunked/streamed response behavior;
- bounded backpressure.

## 10.3 HTTP/2 acceptance

Keep existing requirements:

- TLS ALPN `h2` / `http/1.1`;
- preface + SETTINGS validation;
- frame parser/writer;
- stream lifecycle and monotonic stream IDs;
- HPACK encoder/decoder/dynamic table/Huffman;
- connection + stream flow control;
- GOAWAY drain;
- per-stream/aggregate backpressure;
- Rapid Reset/control-frame/header-block abuse bounds.

## 10.4 HTTP/3 connection startup

Native HTTP/3 requires:

```text
UDP
  ↓
QUIC v1 + TLS 1.3
  ↓ ALPN h3
HTTP/3 connection
```

Requirements:

- advertise/accept `h3` ALPN through the QUIC engine;
- reject unsupported application protocols;
- enforce handshake/idle/lifetime limits;
- create the local HTTP/3 control stream;
- create the local QPACK encoder stream;
- create the local QPACK decoder stream;
- accept exactly one peer control stream, one peer QPACK encoder stream and one peer QPACK decoder stream;
- duplicate critical streams are connection errors;
- closing a critical stream unexpectedly is a connection error;
- SETTINGS must be the first frame on the peer control stream;
- missing/duplicate/invalid SETTINGS fail with RFC 9114 errors;
- unknown SETTINGS are ignored while reserved identifiers are handled according to RFC requirements;
- request streams are QUIC client-initiated bidirectional streams;
- server push is not a 1.0 application feature.

## 10.5 HTTP/3 frame engine

Required HTTP/3 frame handling:

```text
DATA
HEADERS
CANCEL_PUSH        protocol handling
SETTINGS
PUSH_PROMISE       reject/handle according to no-push policy
GOAWAY
MAX_PUSH_ID        protocol handling
unknown extension frames according to RFC 9114
```

Requirements:

- QUIC variable-length integer codec is bounded and 62-bit safe;
- frame type/length parse incrementally across arbitrary stream fragmentation;
- payload allocation obeys local hard limits;
- forbidden frame/stream combinations produce the correct HTTP/3 error;
- unknown extension frames are skipped without unbounded copies;
- request HEADERS/DATA ordering follows RFC 9114;
- trailing HEADERS are represented distinctly from initial headers;
- content-length consistency is enforced at the common transport boundary.

## 10.6 Full QPACK is required for Runwire 1.0

Runwire 1.0 must **not** ship with QPACK permanently dynamic-table-disabled.

Required QPACK features:

- complete RFC 9204 static table;
- encoder dynamic table;
- decoder dynamic table;
- Set Dynamic Table Capacity;
- Insert With Name Reference;
- Insert Without Name Reference;
- Duplicate;
- indexed field lines;
- indexed post-base field lines;
- literal field lines with name reference;
- literal field lines with post-base name reference;
- literal field lines with literal name;
- Required Insert Count wrap/reconstruction;
- Base / Delta Base handling;
- QPACK encoder stream;
- QPACK decoder stream;
- Section Acknowledgement;
- Stream Cancellation;
- Insert Count Increment;
- Huffman encode/decode;
- blocked field-section retention and bounded unblocking;
- dynamic-entry reference pinning/eviction safety;
- sensitive header never-index policy;
- malformed integer/string/Huffman/table-reference errors;
- bounded compressed and decompressed field-section sizes.

Hard bounds:

```text
qpack_max_table_capacity
qpack_max_blocked_streams
max_compressed_field_section_bytes
max_decoded_field_section_bytes
max_header_fields
max_qpack_encoder_stream_buffer
max_qpack_decoder_stream_buffer
max_retained_blocked_field_section_bytes
max_qpack_work_per_tick
```

A peer exceeding advertised QPACK blocked-stream/table limits must fail deterministically rather than allocate more state.

## 10.7 HTTP/3 request/pseudo-header validation

HTTP/3 request header validation must provide the same application-normalized result as HTTP/2 while applying HTTP/3 field rules:

- pseudo-headers precede regular fields;
- duplicates and invalid pseudo-headers rejected;
- required `:method`, `:scheme`, `:path`, `:authority` combinations validated;
- forbidden connection-specific fields rejected;
- `TE` only permits `trailers`;
- lowercase header names enforced;
- authority/Host conflict prevented;
- trailers cannot contain request-routing/framing fields;
- field count/bytes remain under hard local bounds.

Shared validator logic should be protocol-neutral where RFC semantics are identical instead of duplicating HTTP/2-specific code unnecessarily.

## 10.8 QUIC and HTTP/3 flow control/backpressure

The selected QUIC engine owns packetization, congestion control, loss recovery, crypto packet protection and transport-level flow control mechanics.

Runwire owns application-facing policy:

- consume request data incrementally;
- stop reading/application delivery under pressure where the engine permits;
- do not enqueue response DATA beyond per-stream/aggregate limits;
- resume producers on writable/credit progress;
- bound control/QPACK stream output separately from response DATA;
- one blocked/slow HTTP/3 stream must not stall siblings;
- QPACK blocked streams count against explicit limits;
- stream cancellation releases application/body/QPACK references promptly.

## 10.9 HTTP/3 graceful drain

Drain sequence:

```text
worker/server draining
      ↓
stop admitting new QUIC connections where possible
      ↓
HTTP/3 GOAWAY with safe request-stream boundary
      ↓
reject later requests according to RFC 9114
      ↓
allow active streams within deadline
      ↓
close QUIC connection with NO_ERROR / appropriate code
      ↓
force termination after supervisor grace deadline
```

GOAWAY state must be idempotent and bounded.

## 10.10 0-RTT and migration policy

For Runwire 1.0:

- QUIC 0-RTT must be disabled by default;
- if the engine exposes 0-RTT, Runwire must not dispatch replay-unsafe application requests as ordinary trusted requests without an explicit future policy;
- connection migration/address rebinding may be supported by the QUIC engine, but Runwire must not use peer-address stability as an authentication boundary;
- application peer metadata must document that QUIC peer addresses may change.

## 10.11 HTTP/3 abuse resistance

Release tests must cover:

- excessive QUIC connection attempts/handshake failures;
- stream-open/reset/STOP_SENDING churn;
- excessive unidirectional stream creation;
- duplicate critical streams;
- SETTINGS/control-stream floods;
- oversized/fragmented frame headers and payloads;
- QPACK encoder/decoder instruction floods;
- QPACK dynamic-table churn/eviction pressure;
- blocked-stream amplification;
- compressed/decompressed header amplification;
- malformed Required Insert Count/Base references;
- unknown-frame CPU amplification;
- request bodies that stall after headers;
- slow readers with many concurrent response streams;
- connection close while streams/QPACK references are active.

Limits count work/state pressure, not only raw UDP bytes.

---

# 11. Generic protocols and WebSocket

Generic raw/line/length-prefixed protocols remain supported independently of HTTP.

WebSocket is optional for the initial 1.0 launch unless completed without weakening HTTP release gates. If included, keep it wire-level only.

WebTransport/HTTP Datagrams/MASQUE are explicitly post-1.0 features and must not delay core HTTP/3 request/response correctness.

---

# 12. Supervisor and worker lifecycle

The prefork supervisor remains reusable for HTTP workers and generic tasks.

Required behavior:

- configured worker counts;
- fork/spawn and child normalization;
- restart budgets/backoff;
- readiness;
- graceful/forced stop;
- rolling reload/generation replacement;
- child reaping with EINTR/ECHILD handling;
- no zombies;
- bounded lifecycle event failures;
- runtime/control status snapshots.

HTTP/2 and HTTP/3 drain must integrate with worker generations rather than being bypassed by immediate worker termination.

---

# 13. Process runner

Primary invariant:

> executable + argv, not shell strings.

Required controls:

- executable policy;
- argv/env/cwd/stdin bounds;
- CAPTURE/STREAM/INHERIT/NULL output modes;
- concurrent stdout/stderr drain;
- wall-clock timeout;
- terminate → kill escalation;
- output ceilings/truncation policy;
- deterministic pipe/process closure;
- no implicit shell execution.

---

# 14. Security boundary

Trusted Foundation/Webrick/Omnibus workers may use prefork/native persistent execution.

Untrusted uploaded/user code requires a separate executable/runtime/UID/GID/OS sandbox boundary. Runwire can orchestrate such a process but does not replace seccomp/AppArmor/SELinux/container/bwrap/systemd sandboxing.

No PHP `@` error-suppression operators are allowed anywhere in Runwire source or tests. Expected warnings/errors must use explicit result/warning handling.

---

# 15. Integration boundaries

## Webrick

Runwire supplies only transport-level request/response information:

- method;
- target;
- protocol version (`1.1`, `2`, `3`);
- ordered/normalized headers;
- streaming body;
- peer/local metadata;
- encrypted state;
- response writer/backpressure.

## Foundation

Foundation owns CLI/config/release generation/application scopes and selects the Runwire runtime.

## Omnibus

Omnibus owns queue/message semantics. Runwire may own generic process supervision only.

## Pathwise / ReqShield

No reverse dependency. Pathwise owns storage/path safety; ReqShield owns validation/intent. Foundation authorizes and passes already-trusted execution inputs to Runwire.

---

# 16. Runtime drivers and OPcache

Supported driver enum/config for 1.0:

```text
auto
native
fpm
frankenphp
swoole
roadrunner
```

OPcache remains orthogonal:

```text
auto
on
off
required
```

`auto` selection must distinguish installed capability from active host.

Host-owned runtimes must not start competing Runwire listeners/event loops/process pools.

Native remains the only Runwire driver that owns the HTTP/1.1, HTTP/2 and HTTP/3 wire implementations directly.

---

# 17. Runtime capability model

Capabilities must include at minimum:

```text
persistent_process
persistent_application
owns_listener
owns_event_loop
owns_worker_pool
supports_fork
supports_signals
supports_async_io
supports_coroutines
supports_graceful_reload
supports_worker_recycle
supports_http1
supports_http2
supports_http3
owns_http1_wire
owns_http2_wire
owns_http3_wire
supports_tls_alpn
supports_quic
supports_websocket
supports_opcache
supports_opcache_cli
```

Rules:

- native `supports_http3` / `owns_http3_wire` are true only when the QUIC capability required by Runwire is actually available;
- host driver `supports_http3` may be true when the host terminates HTTP/3, while `owns_http3_wire` remains false;
- consumers should use capabilities, not runtime-name switches where possible.

---

# 18. Host-driver expectations

## FPM

Thin request-bound adapter; no Runwire listener/loop/prefork. HTTP/2/3 may exist upstream but Runwire does not own those wires.

## FrankenPHP

Support classic and worker modes; preserve fresh execution scope per request; host owns listener/HTTP stack. Report HTTP/1/2/3 support separately from Runwire ownership.

## Swoole/OpenSwoole

Adapt host lifecycle/request callbacks. Never run a nested Runwire select loop or HTTP worker pool.

## RoadRunner

Use host worker ecosystem where practical; no second listener/pool; fresh application execution scope per exchange.

Explicit driver selection fails fast if unavailable; never silently falls back.

---

# 19. Control, status and observability

Control plane supports run/stop/reload/recycle/status through programmatic APIs and a bounded Unix-domain local control socket where applicable.

Observability should cover:

- worker/listener/connection lifecycle;
- bytes/connections/streams;
- HTTP/2 active streams/GOAWAY/flow stalls/HPACK sizes;
- HTTP/3 QUIC connections/request streams/GOAWAY;
- QPACK table capacity, blocked streams and encoder/decoder instruction work without logging header values;
- protocol errors by stable reason/error code;
- backpressure transitions;
- process execution start/exit/timeout.

Instrumentation-off path must remain cheap.

---

# 20. Error taxonomy

Stable high-level families:

```text
RuntimeException
CapabilityUnavailable / RuntimeUnavailableException
ConfigurationException
ListenerException
ProtocolException
ConnectionException
SupervisorException
ProcessStartException
ControlException
```

HTTP/3 errors use RFC 9114/9204 error codes, including `H3_*` and `QPACK_*`, mapped to stream or connection closure according to protocol rules rather than crashing the worker.

---

# 21. Resource limits

1.0 requires bounded policy for:

- process execution time/output/argv/env;
- TCP/UDP/QUIC connections;
- TCP buffers;
- HTTP/1 body/header framing;
- HTTP/2 streams/frames/header blocks/HPACK/flow-control queues;
- HTTP/3 QUIC streams/frames/QPACK/control streams/blocked sections;
- idle/lifetime timeouts;
- restart frequency/budget;
- aggregate worker resource pressure.

No network/parser path may grow memory solely according to peer-provided lengths/counts without a local hard ceiling.

---

# 22. Performance rules

- no framework/container lookup in transport hot paths;
- no process-global locks for normal per-worker traffic;
- immutable static protocol configuration;
- incremental parsers;
- streaming bodies;
- bounded geometric buffers;
- fair stream scheduling for HTTP/2 and HTTP/3;
- HPACK/QPACK tables are connection-local and bounded;
- avoid unnecessary request copies between Runwire and Webrick;
- no benchmark-only fast path;
- QUIC packet crypto/loss recovery/congestion control remain in the mature QUIC engine rather than being reimplemented in PHP.

---

# 23. Test matrix

## Event loop

- watcher add/remove while dispatching;
- timer ordering/cancellation;
- deferred ordering;
- closed descriptor behavior;
- idle no-busy-spin;
- optional backend parity.

## TCP/UDP/TLS

- IPv4/IPv6;
- Unix socket;
- UDP datagram;
- TLS handshake/ALPN;
- partial I/O;
- send/receive pressure;
- limits/timeouts;
- peer disconnect;
- bounded slow-client memory.

## HTTP/1.1

- framing/smuggling/slowloris/body limits;
- chunked/fixed bodies;
- keep-alive;
- streamed responses;
- HEAD semantics.

## HTTP/2

- preface/SETTINGS;
- all required frames;
- CONTINUATION;
- HPACK static/dynamic/Huffman;
- pseudo-header validation;
- flow control;
- RST/GOAWAY;
- Rapid Reset/control-frame/header abuse;
- sibling progress under slow streams.

## HTTP/3 + QUIC

- QUIC extension capability detection absent/present;
- QUIC v1 handshake with TLS 1.3 and `h3` ALPN;
- client/server control streams;
- SETTINGS first/duplicate/missing/invalid cases;
- bidirectional request streams;
- unidirectional stream types and duplicate critical streams;
- HTTP/3 DATA/HEADERS/GOAWAY frame parsing under fragmentation;
- unknown frame handling;
- request/trailer ordering rules;
- QUIC stream reset/STOP_SENDING cleanup;
- graceful GOAWAY/drain;
- peer address/migration metadata behavior if exposed by engine;
- no accidental 0-RTT application dispatch by default.

## QPACK

- RFC 9204 static-table vectors;
- dynamic-table capacity updates;
- all encoder-stream instruction forms;
- all decoder-stream instruction forms;
- Required Insert Count wrapping;
- positive/negative Delta Base;
- pre-base and post-base indexed/literal forms;
- Huffman valid/invalid/bounded decode;
- blocked field-section queue/unblocking;
- blocked-stream ceiling;
- retained blocked-byte ceiling;
- pin/ack/cancel eviction safety;
- malformed index/reference/integer/string errors;
- encoder/decoder instruction buffer ceilings;
- sensitive header no-index behavior.

## Protocol parity

The same handler must produce semantically equivalent results over HTTP/1.1, HTTP/2 and HTTP/3 for:

- method/target;
- duplicate headers;
- body streaming;
- trailers where supported;
- HEAD/204/304 body suppression rules;
- errors;
- cancellation/cleanup;
- response backpressure.

## Supervisor/process/isolation

Retain startup/restart/reload/reaping/no-zombie, ProcessRunner shell-safety/deadlock/timeout/output, and persistent-state isolation tests.

---

# 24. CI / Security & Standards

Normal PHPForge matrix:

```text
PHP 8.4 / prefer-lowest
PHP 8.4 / prefer-stable
PHP 8.5 / prefer-lowest
PHP 8.5 / prefer-stable
analysis lanes
clean install
```

Normal matrix extensions continue to include process/network capabilities but **do not require QUIC**, proving optional absence is supported.

Dedicated HTTP/3 integration job(s) must:

- run on PHP 8.4 and PHP 8.5 where the QUIC engine supports them;
- install the QUIC extension explicitly using its supported installer (currently PIE for `mikepultz/php-quic`), not by pretending it is a normal PECL package;
- verify `extension_loaded('quic')` and required `Quic\Listener`, `Quic\Connection`, `Quic\Stream`, `Quic\poll` APIs;
- run focused HTTP/3/QPACK/QUIC tests;
- run at least one real native server/client interoperability fixture;
- fail if HTTP/3 tests are silently skipped in the extension-present lane;
- preserve a separate extension-absent capability test.

No broad test skips and no `@` error suppression.

---

# 25. Interoperability acceptance

Native HTTP/3 must be exercised against at least two independent mature clients/tools where available in CI or release validation, for example:

- `curl` built with HTTP/3 support;
- `nghttp3`/`h2load`-family tooling or another independent QUIC/HTTP/3 client;
- browser/manual smoke is useful but not the only acceptance proof.

Interoperability cases:

- GET/POST/body streaming;
- concurrent request streams;
- large response;
- QPACK dynamic references;
- GOAWAY/drain;
- malformed peer behavior where tooling allows.

---

# 26. Soak and fault acceptance

Run production-style soak tests for:

- HTTP/1.1 keep-alive;
- multiplexed HTTP/2;
- multiplexed HTTP/3 over QUIC;
- QPACK dynamic-table churn and blocked streams;
- slow readers/writers;
- connection/stream churn;
- worker crashes;
- rolling reload during traffic;
- process execution churn;
- shutdown with active TCP and QUIC connections.

Acceptance:

- no unbounded RSS growth attributable to Runwire;
- no FD/child-state drift;
- no zombies;
- no unbounded retained QPACK/HPACK state;
- no stale TCP/QUIC connections beyond drain deadlines;
- no cross-request application state leakage;
- bounded overload degradation.

---

# 27. Benchmark matrix

Record separately:

- raw TCP single/multi-worker;
- HTTP/1.1 keep-alive;
- HTTP/2 multiplexing;
- HTTP/3 multiplexing over QUIC;
- minimal dynamic Webrick route over each protocol once adapter work begins;
- small/large streaming responses;
- slow clients;
- connection churn;
- memory per idle/active TCP connection and QUIC connection;
- HPACK/QPACK compression CPU/memory;
- reload capacity dip.

For host drivers, benchmark FPM, FrankenPHP classic/worker, Swoole/OpenSwoole and RoadRunner separately where available. Attribute host cost vs Runwire adapter cost; do not publish misleading universal claims.

---

# 28. Documentation before 1.0

Document:

- architecture/lifetime model;
- native TCP/UDP/Unix quick starts;
- native HTTP/1.1 + HTTP/2 + HTTP/3;
- TLS ALPN and QUIC `h3` ALPN;
- QUIC extension installation/capability diagnostics;
- HTTP/2 HPACK/flow-control tuning;
- HTTP/3 QPACK/stream/control/GOAWAY tuning;
- HTTP/3 security limits and 0-RTT default policy;
- backpressure and overload behavior;
- structured ProcessRunner and shell-safety;
- fork-safety/pre-fork clean-parent rule;
- supervisor reload/shutdown/control plane;
- host-driver capability matrix;
- trusted worker vs untrusted execution boundary;
- benchmark methodology.

---

# 29. Public API discipline

Likely stable areas:

```text
Runwire\Runtime
Runwire\Server / Listener definitions
Runwire\Loop contract
Runwire\Network\Connection and bounded results
Runwire\Protocol contracts
Runwire\Http\ProtocolVersion
Runwire\Http\HttpRequest / ResponseWriterInterface
Runwire\Supervisor
Runwire\WorkerGroup / lifecycle values
Runwire\Process\Command / ProcessRunner / ProcessResult
Runwire\RuntimeCapabilities
```

HTTP/1/2/3 parser state machines, QPACK/HPACK tables and QUIC-engine-specific adapters should remain internal or narrowly scoped unless there is a compelling stable public use case.

---

# 30. Security invariants

Release blocking:

- no implicit shell path;
- no PHP `@` suppression;
- no unbounded network/parser/protocol buffers;
- no unbounded HTTP/2 streams/HPACK/control work;
- no unbounded HTTP/3 streams/QPACK/control work;
- no attacker-controlled dynamic-table capacity beyond local ceilings;
- no process-global mutable runtime topology;
- no cross-request application state retained by Runwire;
- no pre-fork application DB/cache/broker connections reused by children;
- signals do not execute arbitrary application work reentrantly;
- all children are reaped;
- shutdown/reload is bounded;
- sensitive argv/env/header values are not logged by default;
- untrusted PHP is never described as secure merely because it runs in a forked child;
- QUIC engine cryptography/congestion/loss recovery is delegated to a maintained native implementation rather than custom PHP crypto/transport algorithms.

---

# 31. Foundation launch sequence

Runwire must be completed first. Updated order:

```text
1. Runwire process + supervisor primitives                         ✅
2. Runwire loop + TCP/TLS/UDP/Unix connection layer              ✅
3. Version-neutral HTTP transport + HTTP/1.1                     ✅
4. Native HTTP/2 + HPACK + flow control + ALPN                   ✅ core
5. Native HTTP/3 framing + full bounded QPACK                    🔄 active
6. QUIC v1/TLS 1.3 native HTTP/3 worker + h3 ALPN                ⬜
7. HTTP/1.1↔HTTP/2↔HTTP/3 parity + abuse/interoperability        ⬜
8. Runwire host-driver execution paths                            ⬜
9. Runwire aggregate QA/soak/benchmarks/docs/release             ⬜
10. Webrick Runwire adapter integration                           ⬜
11. Foundation native serve/host-driver integration               ⬜
12. Omnibus/boundary integration                                  ⬜
13. Foundation 3 final acceptance                                 ⬜
```

Do not leave Runwire mid-release to implement consumer integrations.

---

# 32. Non-goals for Runwire 1.0

Do not expand 1.0 into:

- DI/MVC/ORM/cache/queue frameworks;
- application auth/session/validation/storage semantics;
- custom coroutine ecosystem;
- cluster/service-discovery/Kubernetes replacement;
- arbitrary remote shell;
- fake PHP sandbox;
- custom QUIC cryptography/congestion/loss-recovery stack;
- HTTP Datagrams;
- WebTransport;
- MASQUE/connect-udp;
- HTTP/3 server push application API;
- mandatory QUIC dependency for users who do not enable native HTTP/3.

---

# 33. Runtime-driver completion gate

Runwire 1.0 additionally requires:

- [x] runtime enum/config includes `auto`, `native`, `fpm`, `frankenphp`, `swoole`, `roadrunner`;
- [x] OPcache modeled independently as `auto|on|off|required`;
- [x] environment probing distinguishes hosted vs available runtimes;
- [x] explicit runtime selection validation exists;
- [x] native HTTP/1.1 engine exists;
- [x] native HTTP/2 engine exists;
- [x] HTTP/3 capability fields exist;
- [ ] native HTTP/3 engine passes all completion gates below;
- [ ] FPM driver execution is wired/tested;
- [ ] FrankenPHP classic + worker execution is wired/tested;
- [ ] Swoole/OpenSwoole execution is wired/tested;
- [ ] RoadRunner execution is wired/tested;
- [ ] host modes never start competing loops/listeners/pools;
- [ ] persistent-state isolation passes for every persistent driver;
- [ ] host option namespaces are bounded/validated;
- [ ] capability reporting distinguishes HTTP protocol support from Runwire wire ownership for HTTP/1/2/3;
- [ ] host benchmarks attribute adapter overhead separately.

---

# 34. HTTP/3 implementation gate

HTTP/3 is part of Runwire 1.0 and blocks release.

Required implementation:

- [x] `ProtocolVersion::HTTP_3`;
- [x] `supports_http3`, `owns_http3_wire`, `supports_quic` capability fields;
- [x] QUIC capability probing surface;
- [x] QUIC variable-length integer codec;
- [x] incremental HTTP/3 frame parser/writer core;
- [x] RFC 9204 QPACK static table core;
- [x] bounded QPACK dynamic table core;
- [x] QPACK encoder/decoder instruction stream primitives;
- [x] blocked field-section retention/unblocking core;
- [ ] full protocol-core PHPForge/static/style validation green;
- [ ] QPACK reference accounting/eviction/ack/cancel edge cases green;
- [ ] HTTP/3 SETTINGS/control stream state machine;
- [ ] request-stream state machine and HEADERS/DATA/trailer sequencing;
- [ ] HTTP/3 request pseudo-header validator/shared validator refactor;
- [ ] HTTP/3 response writer using the common response contract;
- [ ] QUIC engine adapter abstraction;
- [ ] `mikepultz/php-quic` adapter;
- [ ] native QUIC listener/connection worker lifecycle;
- [ ] `h3` ALPN/TLS 1.3 handshake;
- [ ] local/peer control + QPACK unidirectional streams;
- [ ] request stream dispatch into `HttpRequest` with `ProtocolVersion::HTTP_3`;
- [ ] QUIC/HTTP application backpressure composition;
- [ ] RESET_STREAM / STOP_SENDING cancellation cleanup;
- [ ] GOAWAY/drain/reload integration;
- [ ] 0-RTT disabled/replay-safe policy enforced;
- [ ] QUIC extension-present CI lane;
- [ ] QUIC extension-absent capability lane;
- [ ] independent client interoperability tests;
- [ ] HTTP/3 abuse/fault suite;
- [ ] HTTP/3 soak test;
- [ ] HTTP/3 benchmarks;
- [ ] HTTP/1.1 ↔ HTTP/2 ↔ HTTP/3 Webrick-semantic parity.

---

# 35. Future plan after Runwire 1.0

HTTP/3 is **not** a future item anymore.

Post-1.0 candidates include only additional capabilities such as:

- WebTransport;
- HTTP Datagrams;
- MASQUE/connect-udp;
- extended migration/path-management policy beyond the QUIC engine baseline;
- optional 0-RTT application policy after replay-safety design;
- alternative QUIC engine adapters;
- more advanced HTTP priority scheduling;
- optional WebSocket-over-HTTP/2/3 capabilities where justified.

These must not weaken or delay correctness of the 1.0 HTTP/1.1/2/3 request-response stack.

---

# 36. Runwire 1.0 completion gate

Runwire 1.0 is release-ready only when all of the following are true:

- [x] runtime topology is instance-owned/freezeable;
- [x] select event loop is correct and bounded under existing test coverage;
- [x] TCP/TLS/Unix/UDP connection lifecycle exists with bounded buffers;
- [x] HTTP/1.1 native engine exists with framing/backpressure/security tests;
- [x] HTTP/2 native engine exists with HPACK/flow-control/abuse tests;
- [ ] HTTP/3 native engine over QUIC v1/TLS 1.3 passes RFC 9114 acceptance;
- [ ] full QPACK passes RFC 9204 dynamic/static/Huffman/blocking/ack/cancel acceptance;
- [ ] QUIC/HTTP/3 stream/control/QPACK state remains bounded under abuse;
- [ ] native HTTP/1.1, HTTP/2 and HTTP/3 share application-semantic parity;
- [ ] native TLS ALPN negotiates HTTP/2/HTTP/1.1 correctly;
- [ ] native QUIC ALPN negotiates `h3` correctly;
- [ ] native HTTP/3 graceful GOAWAY/drain integrates with supervisor reload;
- [ ] dedicated QUIC-present CI passes on supported PHP 8.4/8.5 lanes;
- [ ] QUIC-absent installation/capability behavior remains green;
- [ ] FPM/FrankenPHP/Swoole/RoadRunner advertised drivers are executable and tested;
- [ ] trusted prefork supervision handles restart/reload/shutdown/reaping without zombies;
- [x] structured ProcessRunner avoids implicit shell execution and enforces bounds;
- [ ] full PHP 8.4/8.5 PHPForge matrix is green at final head;
- [ ] HTTP/1/2/3 soak/fault tests show no unbounded memory/FD/state growth;
- [ ] HTTP/3 interoperability is proven against independent client implementations;
- [ ] benchmarks are recorded without benchmark-only shortcuts;
- [ ] public docs cover HTTP/1/2/3, QUIC/QPACK, limits and deployment;
- [ ] draft PR remains the continuous review/CI surface until these gates close;
- [ ] Runwire 1.0 is tagged/released before Foundation 3 final release consumes `infocyph/runwire:^1.0`.

---

# 37. Immediate implementation handoff

Current continuation order:

```text
1. Canonical plan/tracker HTTP/3 update                         ← this document
2. CI: QUIC-present + QUIC-absent coverage
3. Validate/harden HTTP/3 framing + dynamic QPACK core
4. HTTP/3 SETTINGS/control stream state machine
5. HTTP/3 request/response stream state + common HTTP mapping
6. QUIC adapter abstraction + php-quic implementation
7. Native HTTP/3 worker/listener lifecycle + h3 ALPN
8. Cancellation/backpressure/GOAWAY/drain
9. RFC/abuse/interoperability tests
10. HTTP/1.1 ↔ HTTP/2 ↔ HTTP/3 parity
11. Host-driver execution completion
12. Runwire-only soak/bench/docs/final QA
13. Runwire 1.0 release
14. Then Webrick/Foundation/Omnibus integration work
```

The next code change after this plan and CI update must continue at **HTTP/3 protocol validation/hardening**, not consumer integration.
