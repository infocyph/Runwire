# Runwire 1.0 — Process, Supervisor & Network Runtime / Foundation 3 Launch Plan

## Status

Target release: **Runwire 1.0**

Primary launch consumer: **Foundation 3**

Primary HTTP integration: **Webrick**

Primary messaging integration: **Omnibus**

Reference server/runtime: `walkor/workerman` — architectural and benchmark reference only; Runwire must not depend on or clone Workerman.

Priority:

> correctness → process isolation/ownership → network safety/backpressure → persistent-runtime safety → performance → scalability → ergonomics

Runwire is a new low-level Infocyph runtime library. It absorbs the earlier “ProcessGuard” idea and expands it into a reusable process + worker supervision + event loop + network server runtime that can power Foundation directly while remaining framework agnostic.

The package name and namespace are:

```text
Composer:  infocyph/runwire
Namespace: Infocyph\Runwire
```

Recommended tagline:

> A high-performance process and network runtime for PHP.

---

# 1. Core ownership

Runwire owns generic runtime mechanics that remain meaningful without Foundation, Webrick, Omnibus, ReqShield or Pathwise.

## Runwire owns

- process creation/execution primitives;
- trusted fork-based worker supervision;
- spawned-process supervision;
- PID/child bookkeeping;
- signal registration/dispatch and process-group/session mechanics;
- wait/reap semantics;
- restart/backoff and graceful→forced termination primitives;
- worker generation/reload primitives;
- event-loop mechanics;
- timers/deferred callbacks;
- readable/writable stream watchers;
- TCP/UDP/Unix-domain listener mechanics;
- TLS transport mechanics where supported;
- non-blocking connection lifecycle;
- input/output buffering and backpressure;
- connection limits and idle timeouts;
- low-level protocol framing/codec contracts;
- HTTP/1.1 and HTTP/2 wire parsing/serialization required for the native HTTP server;
- TLS ALPN negotiation for `h2` / `http/1.1` in native TLS mode;
- HTTP/2 connection/stream state, HPACK, multiplexing, flow control, graceful drain and abuse limits;
- process/runtime status snapshots and generic lifecycle events;
- safe structured process command execution;
- executable/environment/cwd/output/time policy;
- integration points for stronger external OS sandboxes.

## Runwire does not own

- application DI/container semantics;
- Foundation release-generation/application lifecycle policy;
- HTTP routes/controllers/middleware/business semantics;
- application request validation;
- storage/upload safety;
- message queues/retries/workflows;
- application authorization;
- user/plugin trust decisions;
- cryptography;
- database/cache creation;
- arbitrary uploaded PHP execution;
- a claim that PHP-level function blocking is a secure sandbox.

---

# 2. Dependency direction

Runwire must remain a low-level dependency and never depend upward on application/framework packages.

Required direction:

```text
                    Foundation
                  /     |      \
                 /      |       \
             Webrick  Omnibus   Runwire direct process APIs
                |        |
                +--------+
                    |
                 Runwire
                    |
                 PHP / OS
```

Hard dependency rules:

- Runwire must not require Foundation.
- Runwire must not require Webrick.
- Runwire must not require Omnibus.
- Runwire must not require ReqShield.
- Runwire must not require Pathwise.
- Runwire must not require InterMix.
- Runwire must not require DBLayer or CacheLayer.
- Webrick may optionally integrate Runwire.
- Omnibus may consume Runwire for generic process supervision.
- Foundation consumes Runwire directly and through Webrick/Omnibus adapters.

Keep production dependencies minimal. PHPForge remains development-only.

---

# 3. Runtime baseline

Initial baseline:

```text
PHP: ^8.4
PHPForge: dev-main@dev (development only)
```

Platform capability model:

- `proc_open()` path available where PHP exposes it;
- `ext-pcntl` enables Unix fork/signal/wait supervision features;
- `ext-posix` enables Unix process signalling/session/identity helpers where required;
- `ext-openssl` enables TLS where required;
- `ext-event` may be an optional high-performance loop backend;
- pure PHP `stream_select()` remains the portable baseline event loop.

Do not make `ext-event` mandatory for 1.0.

Do not pretend Windows and Unix have identical process capabilities. Runwire must expose capability detection and fail clearly when a requested feature is unavailable.

---

# 4. Architectural model

The runtime has five distinct lifetimes:

```text
master/runtime lifetime
    |
    +-- listener lifetime
    |
    +-- worker-process lifetime
    |      |
    |      +-- event-loop lifetime
    |      |
    |      +-- connection lifetime
    |             |
    |             +-- request/message/protocol exchange lifetime
    |
    +-- supervised child-process lifetime
```

These lifetimes must not be conflated.

Most importantly:

> A Runwire worker process is not an application request/job execution scope.

Foundation must create a fresh Foundation execution state for each web request, queue message, scheduler execution or other logical application execution.

---

# 5. Configuration topology: build, validate, freeze, run

Avoid Workerman-style process-global configuration as the primary architecture.

Preferred model:

```php
$runtime = Runtime::create();

$runtime->listen(
    Server::tcp('0.0.0.0:8080')
        ->workers(8)
        ->protocol(Http1Protocol::class)
        ->handler($handler),
);

$runtime->run();
```

Exact API naming may change, but the topology must follow:

```text
construct/configure
      ↓
validate
      ↓
freeze
      ↓
run
```

Requirements:

- runtime topology is instance-owned;
- no mutable process-global listener registry;
- listener/server definitions become immutable before workers accept traffic;
- runtime validation happens before fork/listen where possible;
- request/connection hot paths do not repeatedly normalize static configuration;
- frozen configuration can be safely read by long-running workers;
- one PHP process may construct independent runtime instances for tests without state leakage.

---

# 6. Event loop

Introduce a narrow event-loop contract instead of coupling networking directly to one extension.

Required operations:

- readable stream watcher;
- writable stream watcher;
- one-shot timer;
- repeating timer;
- deferred callback;
- watcher/timer cancellation;
- run;
- stop;
- monotonic time access or consistent monotonic timer behavior.

## 6.1 Select backend

Provide a pure-PHP baseline using `stream_select()`.

Requirements:

- no busy-spin when idle;
- deterministic timer scheduling;
- bounded watcher bookkeeping;
- safe removal while callbacks are active;
- errors/closed descriptors are handled without poisoning the loop;
- monotonic deadlines use `hrtime()` where practical;
- no hidden global loop singleton.

## 6.2 Optional event backend

Support `ext-event` only behind an adapter selected by capability/configuration.

- no behavior difference visible to protocol/application code;
- same timer/watcher lifecycle contract;
- no mandatory extension for normal installation;
- benchmark before making it Foundation's production recommendation.

## 6.3 Coroutine non-goal for 1.0

Do not make a custom coroutine scheduler a Runwire 1.0 prerequisite.

Fibers may be used internally only when they provide a measured and bounded benefit. Do not redesign Foundation execution around Runwire Fibers.

---

# 7. Network listener layer

Runwire 1.0 should support:

- TCP listeners;
- UDP listeners;
- Unix-domain sockets on supported Unix systems;
- TLS over TCP;
- IPv4 and IPv6;
- configurable backlog;
- socket/stream context options;
- listener enable/disable lifecycle;
- graceful listener drain/close.

## 7.1 Listener ownership

For prefork mode, choose and document one authoritative listener model.

Preferred initial direction:

```text
master validates/binds listeners
        ↓
fork trusted server workers
        ↓
workers inherit listening descriptors
        ↓
master does not process application connections
```

This provides deterministic bind failure before worker startup and avoids each worker racing to bind.

If `SO_REUSEPORT` support is later added, treat it as an explicit alternate strategy and benchmark it separately.

## 7.2 Fork-safety boundary

The master must remain application-resource-clean before fork.

Do not open in the master before forking if the connection/resource will be used independently by workers:

- DB connections/PDO;
- Redis/Valkey connections;
- broker sockets;
- outbound HTTP pools;
- mutable lock handles;
- process-bound telemetry exporters;
- Foundation application container instances that own such resources.

Listeners intentionally designed for descriptor inheritance are the exception.

---

# 8. Connection layer

Each accepted stream connection must have an explicit object/lifecycle.

Required capabilities:

- non-blocking reads/writes;
- bounded receive buffer;
- bounded send buffer;
- high/low watermarks;
- pause/resume reads;
- backpressure propagation;
- graceful close after pending writes;
- immediate abort close;
- peer/local address metadata;
- idle timeout;
- total connection lifetime timeout where configured;
- per-listener connection ceiling;
- worker connection ceiling;
- byte counters;
- close reason/result taxonomy.

No unbounded string concatenation for network input/output.

Large bodies/streams must be processed incrementally.

---

# 9. Backpressure and overload behavior

Backpressure is a release-blocking requirement, not a post-1.0 optimization.

Runwire must define behavior for:

```text
client sends faster than handler consumes
handler produces faster than socket writes
worker hits connection ceiling
worker output buffer ceiling reached
listener/system is overloaded
```

Requirements:

- pause socket reads when receive-side pressure requires it;
- pause producer/application writes or return explicit pressure state when output exceeds high watermark;
- resume at low watermark;
- connection limits are enforced before memory exhaustion;
- slow-client tests must prove bounded memory;
- no global buffer shared between unrelated connections;
- overload must fail predictably instead of allowing process-wide OOM.

---

# 10. Native HTTP/1.1 + HTTP/2 protocol stack for Foundation/Webrick

Runwire must own only the **wire/server transport** aspects necessary to receive and send HTTP.

Runwire owns:

- request line parsing;
- header framing/parsing;
- header count/byte bounds;
- Content-Length framing;
- chunked request framing;
- connection persistence/keep-alive mechanics;
- request body streaming/bounds;
- response status/header/body serialization;
- chunked/known-length response framing as required;
- connection close semantics;
- malformed framing rejection;
- protocol-level timeout handling.

Webrick owns:

- Webrick Request/Response objects;
- routing;
- middleware;
- controller dispatch;
- content negotiation;
- cookies/security headers/application HTTP semantics;
- error rendering;
- application request limits that are above Runwire's transport hard bounds.

Foundation owns:

- application graph;
- request execution scope;
- auth/session/database application state;
- selected Webrick/Runwire configuration;
- release generation and deployment policy.

## 10.1 HTTP parser security requirements

At minimum cover:

- bounded request line;
- bounded individual header line;
- bounded total header bytes;
- bounded header count;
- invalid/multiple conflicting Content-Length;
- Transfer-Encoding / Content-Length ambiguity;
- malformed chunk sizes;
- oversized chunk metadata;
- premature EOF;
- invalid control characters;
- request smuggling edge cases;
- slow headers/body timeouts;
- body ceiling before unbounded buffering;
- keep-alive request count ceiling if configured.

Do not expose partially parsed attacker-controlled structures as trusted application input.

## 10.2 Protocol targets and standards baseline

Runwire 1.0 native HTTP has two release-required wire protocols:

```text
HTTP/1.1  REQUIRED
HTTP/2    REQUIRED
```

Standards baseline:

- HTTP semantics: RFC 9110;
- HTTP/1.1 message syntax/routing: RFC 9112;
- HTTP/2: RFC 9113;
- HPACK: RFC 7541;
- TLS ALPN: RFC 7301;
- extensible HTTP priorities: RFC 9218 where/when priority signaling is consumed;
- WebSocket over HTTP/2 extended CONNECT: RFC 8441 if/when that optional integration is enabled.

Do not implement against obsolete RFC 7540 behavior where RFC 9113 intentionally changed/deprecated it. In particular, the old RFC 7540 dependency-tree priority scheme is deprecated and HTTP/1.1 Upgrade-to-`h2c` is not a required production path.

The common Runwire HTTP transport contract must prevent Webrick/Foundation from caring whether the request arrived through HTTP/1.1 or an HTTP/2 stream.

```text
HTTP/1.1 connection/request ─┐
                             ├─ Runwire HTTP transport request/response contract -> Webrick
HTTP/2 connection/stream ────┘
```

Application routing, middleware, authentication, validation and response semantics remain above the wire protocol.

---

## 10.3 HTTP/2 negotiation and connection startup

Native TLS listeners must support ALPN negotiation between at least:

```text
h2
http/1.1
```

When both are enabled, Runwire should advertise both and prefer `h2` according to configured protocol order while remaining interoperable with HTTP/1.1 clients.

Required behavior:

- ALPN-selected `h2` enters the HTTP/2 connection state machine directly;
- ALPN-selected `http/1.1` enters the HTTP/1.1 parser;
- unknown/unsupported negotiated protocols fail clearly;
- TLS handshake timeout is bounded;
- no protocol sniffing ambiguity after ALPN establishes the protocol;
- protocol choice is immutable for that TCP/TLS connection.

Cleartext HTTP/2 policy:

- prior-knowledge `h2c` may be supported as an explicit opt-in native listener capability;
- HTTP/1.1 `Upgrade: h2c` is not required for 1.0 and should not be the default because RFC 9113 deprecates that upgrade path;
- production documentation should recommend TLS + ALPN for public HTTP/2 service.

The HTTP/2 client connection preface and first SETTINGS exchange must be validated before application streams are accepted.

---

## 10.4 HTTP/2 frame engine

Implement an incremental binary frame parser/writer with strict bounds. It must not require buffering an arbitrary connection payload before decoding frames.

Required frame support/handling:

```text
DATA
HEADERS
PRIORITY          protocol-compatible handling; legacy priority semantics are deprecated
RST_STREAM
SETTINGS
PUSH_PROMISE      parse/protocol handling as required; server push is not a 1.0 app feature
PING
GOAWAY
WINDOW_UPDATE
CONTINUATION
unknown extension frames according to RFC 9113 rules
```

Requirements:

- validate the fixed frame header before allocating payload storage;
- enforce peer/local maximum frame size before payload growth;
- validate stream-ID rules for each frame type;
- validate frame-specific length/flag combinations;
- SETTINGS ACK and value rules are enforced;
- PING payload length is enforced;
- WINDOW_UPDATE increment zero/overflow is rejected correctly;
- HEADERS/PUSH_PROMISE continuation blocks remain contiguous until END_HEADERS as required;
- an unfinished header block cannot grow without a configured byte/frame/time ceiling;
- unknown extension frames can be skipped without copying into unbounded buffers;
- protocol errors are mapped to stream or connection failure according to RFC 9113 rather than crashing the worker.

No application code receives raw frame parser internals.

---

## 10.5 HTTP/2 stream state and multiplexing

Each HTTP/2 exchange is an explicit connection-owned stream with a state machine covering the RFC-defined lifecycle, conceptually:

```text
idle
reserved (where applicable)
open
half-closed local
half-closed remote
closed
```

Requirements:

- client-initiated stream identifiers are validated and monotonic;
- closed stream state is released promptly without losing protocol bookkeeping required to reject invalid reuse;
- maximum concurrent streams is locally bounded regardless of peer behavior;
- one stalled stream cannot block unrelated streams at the Runwire scheduler/application-dispatch layer;
- stream cancellation propagates to the request/body producer where safe;
- stream completion performs deterministic buffer/body/application callback cleanup;
- connection close cancels/cleans every remaining stream exactly once;
- stream objects do not become Foundation request scopes; they only carry transport state.

Runwire must distinguish:

```text
connection lifetime
    ├─ stream 1 -> application execution A
    ├─ stream 3 -> application execution B
    └─ stream 5 -> application execution C
```

Foundation/Webrick must create separate logical request execution state for every stream even when streams overlap on one connection.

---

## 10.6 HPACK header compression

Provide a dedicated HPACK implementation/component rather than mixing compression-table state into the general HTTP/2 connection class.

Conceptual internal split:

```text
Http2
 ├─ FrameParser / FrameWriter
 ├─ ConnectionState
 ├─ StreamState
 ├─ FlowController
 └─ Hpack
      ├─ Decoder
      ├─ Encoder
      ├─ DynamicTable
      └─ Huffman decoder/encoder where implemented
```

HPACK requirements:

- decoder dynamic table is connection-local;
- encoder dynamic table is connection-local;
- table size honors peer SETTINGS while also respecting a Runwire hard ceiling;
- dynamic table updates are validated in the correct header-block position;
- decoded header-list bytes/count are bounded independently from compressed bytes;
- compressed input cannot cause unbounded decompressed allocation;
- malformed integer/Huffman/string encodings fail deterministically;
- Huffman decode has bounded work/output and rejects invalid terminal padding/state;
- sensitive headers may use never-indexed encoding according to Runwire/Webrick policy;
- HPACK state is destroyed with its owning connection and never shared globally across clients.

Do not optimize HPACK by creating mutable global tables.

---

## 10.7 HTTP/2 request-header and pseudo-header validation

Before mapping an HTTP/2 request into the common Runwire HTTP request transport, validate HTTP/2-specific field semantics.

At minimum:

- header field names obey HTTP/2 lowercase requirements;
- pseudo-header fields appear before regular fields;
- pseudo-header fields are not duplicated;
- only request-appropriate pseudo-headers are accepted;
- required `:method`, `:scheme`, `:path`, `:authority` combinations are validated according to request form/CONNECT semantics;
- connection-specific HTTP/1.x fields are rejected where HTTP/2 forbids them;
- `TE` is accepted only with the HTTP/2-permitted `trailers` value;
- header-list count and decoded bytes remain under local hard ceilings even when the peer advertises larger values;
- trailers are represented distinctly from initial request headers;
- authority/host normalization does not create two conflicting routing authorities.

HTTP/2 transport validation must not duplicate Webrick application validation.

---

## 10.8 HTTP/2 flow control and backpressure

HTTP/2 flow control must compose with Runwire's existing connection backpressure instead of becoming a parallel unbounded buffering system.

Runwire must track both:

```text
connection flow-control window
stream flow-control window
```

Required behavior:

- never transmit DATA beyond the peer-advertised connection or stream window;
- inbound WINDOW_UPDATE changes credit without bypassing configured memory ceilings;
- inbound DATA consumes receive credit before being accepted into application buffers;
- replenish receive windows based on actual downstream consumption strategy, not simply because bytes were read from the socket;
- per-stream outbound queues are bounded;
- aggregate HTTP/2 connection outbound queue is bounded;
- per-stream inbound/request-body buffering is bounded;
- one slow stream cannot consume the entire connection/worker memory budget;
- control frames required for protocol progress are not deadlocked behind DATA backpressure;
- stream cancellation releases queued buffers and application body resources promptly.

The implementation should use a simple fair scheduler initially. Do not implement a complex priority tree that RFC 9113 has deprecated.

If RFC 9218 priority signals are later consumed, isolate them behind a scheduling policy so the core stream/flow-control state machine remains correct without them.

---

## 10.9 HTTP/2 graceful drain, reload and shutdown

HTTP/2 must integrate with Runwire worker generations and graceful reload.

Required drain sequence conceptually:

```text
worker enters draining
      ↓
stop accepting new connections where appropriate
      ↓
send GOAWAY with an appropriate last processed stream ID
      ↓
refuse/reject new streams beyond drain boundary
      ↓
allow active streams to complete within grace deadline
      ↓
close connection
      ↓
Runwire supervisor may terminate worker after deadline
```

Requirements:

- GOAWAY state is explicit and idempotent;
- multiple shutdown/reload requests do not corrupt last-stream accounting;
- active streams have a bounded drain deadline;
- client disconnect during drain cleans stream/application state;
- graceful worker reload must not silently drop already accepted streams without the configured policy/deadline;
- force termination remains available after grace expiration.

PING may be used for protocol liveness/diagnostics but must not become an unbounded heartbeat flood.

---

## 10.10 HTTP/2 security and abuse resistance

HTTP/2 expands the resource-amplification surface because many logical streams and control frames share one TCP connection. Release acceptance must therefore include explicit abuse controls.

Bound/configure at minimum:

```text
max concurrent streams
max total streams created per connection / bounded churn policy
max frame size accepted under protocol limits
max compressed header-block bytes
max CONTINUATION frames per header block
max decoded header-list bytes
max decoded header count
max HPACK dynamic-table bytes
max pending request-body bytes per stream
max pending response bytes per stream
max pending aggregate bytes per connection
max SETTINGS/PING/RST_STREAM/WINDOW_UPDATE/control-frame rate or work budget
header-block completion timeout
stream idle/request timeout
connection idle/lifetime policy
```

Specific adversarial cases to cover:

- rapid open/reset stream churn (HTTP/2 Rapid Reset style behavior);
- RST_STREAM floods that repeatedly force expensive application setup/cleanup;
- SETTINGS floods/ACK churn;
- PING floods;
- WINDOW_UPDATE floods/overflow attempts;
- endless or excessive CONTINUATION/header blocks;
- HPACK compression/decompression bombs;
- oversized dynamic-table requests;
- streams opened beyond the advertised/local concurrency ceiling;
- invalid/reused/decreasing stream IDs;
- empty-frame/control-frame CPU amplification;
- request bodies that stall after headers;
- outbound clients that stop reading while many streams are active.

Abuse limits should count **work/state pressure**, not only raw socket bytes. Exceeding an abuse threshold should fail the affected stream when safe or send GOAWAY/close the connection when the connection itself is abusive.

Runwire must avoid retaining attacker-controlled closed-stream objects indefinitely merely to remember historical state; use compact bounded bookkeeping sufficient for protocol correctness.

---

## 10.11 HTTP/2 server push and extended protocols

Do not make HTTP/2 server push a Runwire 1.0 application feature. Runwire should remain protocol-correct around peer SETTINGS and must not emit PUSH_PROMISE from normal application responses in 1.0. This avoids committing Webrick/Foundation to an obsolete/poorly deployed application API.

WebSocket over HTTP/2 using RFC 8441 extended CONNECT is a valid future/optional capability. If implemented in 1.0, it must:

- be capability-negotiated;
- reuse HTTP/2 stream flow control/backpressure;
- map the established stream into Runwire's WebSocket wire layer without creating a second TCP socket abstraction;
- preserve independent cleanup from sibling streams.

Its absence must not block core HTTP/2 request/response support.

---

---

# 11. WebSocket and generic protocols

Runwire's generic protocol layer should make custom servers possible without Webrick.

1.0 generic framing support should include lightweight contracts for:

- raw byte stream;
- line-delimited frames;
- length-prefixed frames;
- custom codec/parser implementations.

WebSocket support is desirable for 1.x. If included in 1.0, keep it at the wire/connection layer: handshake/framing/ping/pong/close/backpressure. Do not add application routing/pub-sub semantics that belong elsewhere.

---

# 12. Trusted master/worker supervisor

Provide a reusable prefork supervisor inspired by proven server runtimes but designed as an instance-owned component.

Required behavior:

- configured worker count;
- fork children;
- child slot/generation tracking;
- clean child bootstrap;
- crash detection;
- restart budget;
- bounded restart backoff;
- graceful stop;
- force-kill escalation;
- complete child reaping;
- rolling reload;
- worker recycling;
- lifecycle callbacks/events;
- parent status snapshot;
- no zombie processes.

## 12.1 Signal rules

Signal callbacks should mutate flags/wake the loop only.

Full shutdown/reload/reaping logic runs in normal supervisor control flow.

Handle at least:

- SIGTERM graceful stop;
- SIGINT interactive stop;
- SIGHUP or configured reload signal where applicable;
- SIGCHLD/wait/reap behavior if used;
- restoration/normalization where library usage returns to a host process.

Do not perform complex application work directly inside asynchronous PHP signal callbacks.

## 12.2 Wait/reap correctness

Explicitly handle:

```text
pid > 0     child reaped
pid == 0    no state change for WNOHANG
pid == -1
  EINTR      retry
  ECHILD     reconcile child table
  other      supervisor failure
```

Never infinite-loop because tracked child state disagrees with kernel child state.

## 12.3 Child normalization

Before child application bootstrap:

- reset Runwire-owned master signal handlers;
- unblock Runwire-owned signals;
- normalize async signal mode;
- clear parent-only timer/alarm state owned by Runwire;
- clear parent child/restart bookkeeping;
- identify role/slot/generation explicitly;
- invoke child bootstrap only after this normalization.

Do not attempt to clean arbitrary unknown application state; applications must obey the pre-fork clean-parent contract.

---

# 13. Rolling reload and generations

Foundation already has release generations, but Runwire must own generic process generation mechanics.

Required distinction:

```text
Runwire worker generation
    = process-supervisor generation/instance identity

Foundation release generation
    = application release/config/artifact identity
```

Foundation maps its release generation onto Runwire worker startup policy; Runwire must not inspect Foundation manifests.

Rolling reload requirements:

- start replacement worker(s) with new generation;
- do not route new connections/work to draining workers once drain begins where architecture permits;
- allow active connection/request grace period;
- terminate after configured deadline;
- maintain minimum healthy capacity where possible;
- status snapshot identifies worker state and generation;
- reload storms are coalesced/bounded.

---

# 14. Generic supervised tasks

Runwire supervisor must not be HTTP-only.

It should also be able to supervise trusted long-running callbacks/tasks so Omnibus and Foundation scheduler/worker infrastructure can reuse the same process machinery.

Conceptual model:

```php
$supervisor->group(
    WorkerGroup::callbacks(
        name: 'queue:emails',
        count: 4,
        factory: $factory,
    ),
);
```

The callback/task semantics stay with the consumer library/application.

Runwire knows only:

- process lifecycle;
- startup/shutdown;
- restart/reload;
- health/lifecycle signals;
- status.

It must not know message queues, retries or workflow semantics.

---

# 15. Omnibus integration boundary

Omnibus owns:

- `Consumer`;
- message `Worker` loop;
- queue polling/prefetch;
- retry/failure/settlement;
- worker recycling decisions driven by messages/runtime policy;
- queue-specific lifecycle.

Runwire owns:

- process fork;
- process slots;
- signals;
- wait/reap;
- generic restart/backoff;
- graceful/forced process termination;
- process generation/health mechanics.

Target architecture:

```text
Omnibus WorkerPool compatibility/facade
          |
          | queue policy + Worker factory
          v
Runwire Supervisor / WorkerGroup
          |
          v
pcntl / posix / OS
```

Runwire must not depend on Omnibus.

Omnibus may make Runwire a production dependency if its public process pool delegates to Runwire; avoid keeping a second `pcntl` implementation solely for compatibility once the migration is complete.

---

# 16. Structured process execution — former ProcessGuard scope

Runwire also owns safe generic child command execution.

Primary API invariant:

> Commands are executable + argv, not shell strings.

Conceptual API:

```php
$command = Command::executable('/usr/bin/git')
    ->arguments(['status', '--porcelain'])
    ->timeout(10.0)
    ->maxOutputBytes(1_000_000);

$result = $runner->run($command);
```

Never design the primary API around:

```php
$runner->run('git ' . $userInput);
```

## 16.1 Command policy

Support trusted policy controls such as:

- executable allowlist/registry;
- argv count limit;
- per-argument byte limit;
- total argv byte limit;
- environment allowlist;
- environment value limits;
- controlled cwd;
- stdin size/stream bounds;
- stdout/stderr capture/stream/inherit modes;
- stdout/stderr maximum bytes;
- wall-clock timeout;
- graceful terminate then force kill;
- exit status/result model.

Use `proc_open()` array command form where supported to avoid an unnecessary shell.

Shell execution must be an explicit exceptional API/policy, not the default path.

## 16.2 Registered operation model

Runwire may expose a generic executable/operation registry for trusted application configuration, but must not authorize application users.

Foundation can map:

```text
image.thumbnail
pdf.inspect
git.status
```

to trusted Runwire command definitions.

ReqShield validates the operation identifier/arguments structurally. Foundation authorizes the operation. Runwire executes the already-authorized definition.

---

# 17. Process I/O

Support explicit I/O modes:

```text
CAPTURE
STREAM
INHERIT
NULL
```

Where practical support input as:

- string;
- stream resource;
- callback/chunk producer.

Requirements:

- stdout/stderr drained concurrently to prevent deadlock;
- bounded capture buffers;
- overflow behavior explicit: terminate, truncate-with-flag, or stream-only according to policy;
- child pipes close deterministically;
- descriptors never leak into unrelated workers/processes;
- timeout handling continues draining/reaping safely;
- result records exit status, termination reason and output truncation state.

---

# 18. Privilege and identity primitives

Runwire may provide narrowly scoped Unix identity/session primitives because they are generic process mechanics.

Possible supported operations when `ext-posix` and permissions permit:

- get PID/PPID;
- setsid;
- set group before user;
- setgid/setegid;
- setuid/seteuid;
- initgroups where available;
- process signalling;
- process-group signalling.

Hard rules:

- Foundation must not remain root merely because these APIs exist;
- privilege dropping is one-way in recommended production profiles;
- group identity is dropped/configured before user identity;
- privileged bootstrap should be minimal;
- failures fail closed;
- Runwire must not market these APIs as a complete sandbox.

---

# 19. Trusted server workers vs untrusted-code execution

This distinction is mandatory.

## Trusted workers

Foundation/Webrick/Omnibus application workers are trusted deployment code.

They may use prefork for performance and can inherit loaded PHP extensions from the clean master.

## Untrusted code

Uploaded/user-supplied PHP/scripts/plugins are not safe merely because Runwire created a child with `pcntl_fork()`.

A forked child inherits the PHP runtime and loaded capabilities.

Therefore arbitrary untrusted code must use a separately configured execution boundary such as:

```text
Runwire structured Process command
        ↓
separate PHP binary/php.ini or sandbox launcher
        ↓
dedicated UID/GID
        ↓
external OS boundary
        ├─ seccomp
        ├─ AppArmor/SELinux
        ├─ namespace/container/bwrap/systemd sandbox
        └─ stronger sandbox when threat model requires it
```

Runwire can orchestrate that boundary but cannot replace the OS security boundary.

---

# 20. PHP capability/profile guidance

Document recommended split profiles for Foundation deployments.

Example conceptual profiles:

```text
foundation-supervisor.ini
    pcntl/posix available as required

foundation-worker.ini
    only capabilities required by trusted application worker

untrusted-executor.ini
    separate restricted runtime plus OS sandbox
```

Do not rely solely on `disable_functions` as the security boundary.

Runwire should provide capability diagnostics rather than pretending runtime configuration can always be changed safely after process startup.

Potential diagnostic model:

```php
$capabilities = RuntimeCapabilities::detect();
```

It can report availability of fork, signals, POSIX identity, TLS, event backend and process spawning without exposing application policy.

---

# 21. Pathwise boundary

Pathwise owns untrusted filesystem/path/upload safety.

Runwire must not duplicate:

- upload validation;
- archive validation;
- storage root/mount semantics;
- Pathwise malware scanner policy;
- user-file canonicalization APIs.

When Foundation needs to pass a stored artifact to a registered process operation:

```text
Pathwise resolves/authorizes storage artifact
        ↓
Foundation authorizes operation
        ↓
Runwire receives trusted resolved execution inputs
```

Runwire process cwd/executable rules remain process policy, not a replacement for Pathwise storage safety.

External executable-based malware scanners may be implemented by an application adapter that combines Pathwise's `MalwareScannerInterface` with Runwire structured process execution. Pathwise itself should not require Runwire.

---

# 22. ReqShield boundary

ReqShield validates data and intent.

It must not become a shell/process sandbox.

Correct composition:

```text
user input
   ↓
ReqShield: validates operation ID + scalar/structured arguments
   ↓
Foundation: authorizes capability + selects registered operation
   ↓
Runwire: executes structured process definition
```

Strings containing `exec`, `system`, `pcntl_fork`, etc. remain ordinary data unless a schema/application rule says otherwise.

Runwire must not require ReqShield.

---

# 23. Webrick native adapter contract

Webrick will add a native Runwire runtime adapter.

Recommended flow:

```text
Runwire HTTP/1.1 connection or HTTP/2 connection/stream
        ↓
Runwire version-neutral HTTP request transport object
        ↓
Webrick RunwireRuntimeAdapter
        ↓
Webrick RuntimeRequestContext / routing input
        ↓
Webrick kernel
        ↓
Webrick Response
        ↓
Runwire response writer/connection
```

Runwire must expose enough HTTP transport information for Webrick without forcing Webrick to depend on Runwire internals.

Keep the boundary small and stable:

- method;
- target/path/query;
- protocol version;
- ordered/normalized headers with duplicate semantics preserved;
- body stream;
- peer/local metadata;
- upload/body streaming hooks where required;
- response writer contract or native response transport.

Do not move Webrick routing or middleware into Runwire.

---

# 24. Foundation native server integration

Foundation 3 should treat Runwire as its native persistent server runtime.

Conceptual CLI path:

```text
php foundation serve
        ↓
Foundation config/release selection
        ↓
Runwire Runtime + Supervisor
        ↓
Runwire HTTP listener/workers
        ↓
Webrick RunwireRuntimeAdapter
        ↓
Foundation web execution scope
```

Foundation owns:

- CLI commands/options;
- release-generation selection;
- application graph compilation/loading;
- runtime process registry/operational policy;
- worker bootstrap callback;
- app-specific heartbeat/health semantics;
- request scope creation/cleanup;
- auth/session/database/application state.

Runwire owns generic process/network mechanics.

---

# 25. Foundation runtime unification opportunity

Runwire should be capable of supervising all Foundation long-running process groups without learning Foundation semantics:

```text
Foundation release supervisor policy
        ↓
Runwire
   ├─ web worker group -> Webrick
   ├─ queue worker group -> Omnibus Worker
   ├─ scheduler worker/group -> Foundation scheduler callback
   └─ trusted custom process groups
```

This can remove duplicate `pcntl`/`posix` mechanics from Foundation and Omnibus.

Do not force all four Foundation execution paths into one OS process. The supervisor may manage separate groups/processes.

---

# 26. Control plane

Provide generic control mechanics usable by Foundation and standalone Runwire applications.

1.0 should support programmatic:

- start/run;
- graceful stop;
- force stop;
- reload;
- status snapshot;
- worker listing/state;
- runtime/listener health snapshot.

For external control, prefer a local authenticated-by-filesystem Unix-domain control socket on supported Unix platforms rather than relying only on PID files/signals for structured status.

However, keep the initial control protocol small. Foundation owns its CLI UX and may translate commands to Runwire control operations.

Security requirements:

- local control endpoint path is trusted configuration;
- restrictive filesystem permissions;
- no arbitrary command execution through control messages;
- bounded request/message size;
- versioned/simple control protocol;
- stale socket cleanup is safe;
- PID identity/reuse is not trusted without process/runtime identity correlation.

---

# 27. Runtime identity and status

Expose immutable status DTOs/value objects rather than mutable internal arrays.

Useful fields:

- runtime ID;
- master PID;
- start monotonic/wall time;
- listener names/addresses/state;
- worker group;
- slot;
- PID;
- generation;
- state: starting/ready/draining/stopping/exited/failed;
- restart count;
- connections active/accepted;
- bytes read/written;
- optional application-supplied health metadata with strict bounds.

Runwire status must not automatically expose environment variables, command secrets, headers, request bodies or application credentials.

---

# 28. Observability

Provide low-overhead hooks rather than binding to one telemetry stack.

Events/counters should cover:

- master start/stop;
- worker spawn/ready/exit/restart;
- reload start/complete;
- listener bind/error;
- connection accept/close/reject;
- input/output bytes;
- parser/protocol error;
- HTTP/2 active streams / stream open-close-reset counts;
- HTTP/2 GOAWAY / connection-vs-stream protocol failures;
- HTTP/2 flow-control stalls and backpressure transitions;
- HPACK decoded/compressed header bytes and bounded table size (without logging header values);
- backpressure transitions;
- process command start/exit/timeout;
- supervisor failure.

Requirements:

- instrumentation disabled/no-op path is cheap;
- no per-byte event callback;
- sensitive argv/env values are redacted or not emitted by default;
- application hooks cannot mutate supervisor internals.

---

# 29. Error taxonomy

Define stable high-level exception/result families rather than leaking raw warnings.

Suggested conceptual categories:

```text
RuntimeException
CapabilityUnavailable
ConfigurationException
ListenerException
ProtocolException
ConnectionException
SupervisorException
ProcessStartException
ProcessTimeout
ProcessOutputLimitExceeded
ControlException
```

Do not overproduce tiny exception classes when a stable reason enum/value provides a better API.

Network protocol errors generally close/reject the affected connection; they should not crash the worker unless they expose an invariant failure.

---

# 30. Security invariants

Release-blocking invariants:

- no API takes untrusted shell command strings as the preferred execution surface;
- no implicit `/bin/sh -c` for normal structured commands;
- no unbounded network input/output buffers;
- no unbounded request headers/body buffering;
- no unbounded HTTP/2 stream/frame/header-block/HPACK state;
- HTTP/2 stream/control-frame churn cannot create unbounded CPU or retained state;
- no process-global mutable runtime topology;
- no cross-connection/request application state stored by Runwire;
- no worker child accidentally uses application DB/cache/broker connections created before fork;
- signals never execute arbitrary application shutdown logic reentrantly;
- all children are reaped;
- shutdown/reload has bounded grace periods;
- executable/process environment is explicit;
- sensitive argv/env/output is not logged by default;
- untrusted uploaded PHP is never described as safe merely because it runs in a forked process;
- stronger hostile-code isolation is delegated to OS sandboxing.

---

# 31. Resource limits

Where available, add or integrate bounded resource policy progressively.

1.0 must at least own application-level limits for:

- execution wall time;
- captured output;
- argv/env size;
- network connections;
- network buffers;
- header/body framing;
- HTTP/2 concurrent streams, stream churn and frame/control work budgets;
- HTTP/2 compressed/decompressed header blocks and HPACK tables;
- HTTP/2 per-stream + aggregate connection buffering/flow-control state;
- idle timeouts;
- restart frequency/budget.

OS resource limits (`rlimit`) may be supported where PHP/platform capabilities make them practical, but do not block the portable runtime on unavailable APIs.

Sandbox adapters can apply stronger CPU/memory/PID/file/network restrictions externally.

---

# 32. Performance architecture

Performance rules:

- no framework/container lookup in network hot path;
- no process-global locks for normal per-worker connection handling;
- immutable server/protocol configuration after freeze;
- reuse parser objects only if they contain no cross-connection mutable data;
- buffer growth is bounded/geometric rather than repeated quadratic concatenation;
- avoid unnecessary request copies between Runwire and Webrick;
- body streaming rather than full buffering for large payloads;
- HTTP/2 parser operates incrementally without whole-connection copies;
- HTTP/2 stream scheduling prevents one stream from starving all siblings;
- HPACK dynamic tables remain connection-local and bounded;
- avoid per-frame object/allocation churn on the hottest paths where a simpler bounded representation benchmarks better;
- cached header serialization only when immutable and measured useful;
- `hrtime`/diagnostics only when needed and low overhead;
- no benchmark-only code path.

---

# 33. Workerman comparison baseline

Use Workerman as a reference implementation and benchmark comparator, not a source of copied API/code.

Compare at least:

- single-process raw TCP echo;
- multi-worker raw TCP echo;
- minimal HTTP plaintext response;
- keep-alive HTTP/1.1;
- multiplexed HTTP/2 where the comparator supports it;
- small dynamic Webrick route over HTTP/1.1 and HTTP/2;
- concurrent connections;
- slow clients;
- large streaming response;
- memory per idle/active connection;
- worker crash/restart;
- graceful reload;
- shutdown latency;
- connection churn.

Record:

- requests/second or messages/second;
- p50/p95/p99 latency;
- CPU;
- RSS/master + worker memory;
- memory growth over soak;
- accepted/closed connection counts;
- errors/timeouts;
- reload capacity dip.

Workerman remains the process/runtime and HTTP/1.x reference baseline. If the selected Workerman comparison build does not provide equivalent native HTTP/2 wire support, use a mature HTTP/2-capable host from the supported Runwire driver matrix (for example FrankenPHP, Swoole/OpenSwoole or RoadRunner where its front server exposes HTTP/2) as the protocol-level comparison rather than inventing a false Workerman HTTP/2 comparison.

Do not make public “faster than Workerman” or HTTP/2 performance claims unless repeatable measurements support them.

---

# 34. Test matrix

## 34.1 Event-loop tests

- watcher add/remove while dispatching;
- timer ordering;
- repeating timer cancellation;
- deferred callback ordering;
- closed descriptor behavior;
- no idle busy-spin;
- loop stop/restart contract if restart is supported;
- select backend parity with optional event backend.

## 34.2 Network/connection tests

- TCP accept/read/write;
- IPv4/IPv6;
- Unix socket where supported;
- UDP datagrams;
- TLS handshake/read/write;
- partial reads/writes;
- send buffer high/low watermarks;
- receive backpressure;
- idle timeout;
- connection limit;
- peer disconnect during write;
- bounded memory under slow client.

## 34.3 HTTP tests

### HTTP/1.1

- request line/header parsing;
- duplicate headers;
- Content-Length;
- chunked request;
- keep-alive;
- connection close;
- malformed headers;
- conflicting body framing;
- request-smuggling cases;
- slowloris-style headers/body;
- body limit;
- streamed request body;
- fixed/chunked/streamed response;
- HEAD semantics at transport boundary coordinated with Webrick.

### HTTP/2

- TLS ALPN chooses `h2` / `http/1.1` correctly;
- connection preface;
- SETTINGS + ACK validation;
- all required frame parse/write paths;
- fragmented frame reads/writes;
- HEADERS + CONTINUATION assembly;
- pseudo-header and lowercase-header validation;
- HPACK indexed/literal/dynamic-table cases;
- HPACK Huffman valid/invalid/bounded decode;
- decompressed header-list hard ceiling;
- concurrent stream state transitions;
- stream-ID monotonicity/reuse failures;
- connection + stream flow-control windows;
- WINDOW_UPDATE errors/overflow;
- per-stream and aggregate backpressure;
- RST_STREAM cleanup;
- GOAWAY graceful drain;
- connection-level vs stream-level protocol errors;
- rapid reset/open-close churn;
- SETTINGS/PING/RST_STREAM/WINDOW_UPDATE flood budgets;
- CONTINUATION/header-block flood limits;
- slow body on one stream while sibling streams progress;
- many slow readers with bounded worker memory;
- HTTP/1.1 vs HTTP/2 parity through the same Webrick route/middleware/response semantics;
- Webrick adapter parity against host/SAPI adapters where applicable.

## 34.4 Supervisor tests

- startup worker count;
- fork failure;
- clean exit/replacement;
- crash/restart;
- restart exhaustion;
- EINTR;
- ECHILD reconciliation;
- SIGTERM;
- SIGINT;
- graceful shutdown;
- forced escalation;
- no zombies;
- child state normalization;
- rolling reload;
- repeated reload requests;
- worker generation status;
- parent kept application-resource-clean.

## 34.5 Process runner tests

- argv preserves literal special characters without shell interpretation;
- executable allowlist;
- env filtering;
- cwd policy;
- stdin/stdout/stderr modes;
- timeout;
- output ceiling;
- child exits before signal;
- terminate→kill escalation;
- large simultaneous stdout/stderr without deadlock;
- exit status normalization;
- missing executable;
- disabled/unavailable function/capability diagnostic.

## 34.6 Persistent isolation tests

- sequential connections do not share mutable protocol/application state;
- interleaved connections remain independent;
- Foundation requests in same worker receive distinct execution state;
- error in one request does not poison next request;
- cancelled/aborted request cleanup;
- leaked Fiber/request-local state detection where Foundation adapter uses Fibers;
- worker recycle clears child application state by process replacement.

---

# 35. Soak and fault-injection acceptance

Run production-style soak tests:

- sustained keep-alive HTTP/1.1 traffic;
- sustained multiplexed HTTP/2 traffic with mixed stream lifetimes;
- HTTP/2 reset/control-frame/header-block abuse under bounded policy;
- connection churn;
- slow readers/writers;
- mixed small/streaming responses;
- worker crashes during active traffic;
- rolling reload during traffic;
- repeated child process execution;
- output-heavy child commands;
- stop while processes/connections are active.

Acceptance:

- no unbounded RSS growth attributable to Runwire;
- no zombies;
- no unreaped child table drift;
- no stale connections after reload deadlines;
- no descriptor growth;
- no cross-request Foundation state leakage;
- bounded degradation under overload.

---

# 36. Static analysis / QA

Run normal PHPForge gates with PHP 8.4 and 8.5 lanes where available:

- Composer validation;
- full tests;
- PHPStan/static analysis;
- Rector dry-run where configured;
- coding style;
- lowest supported dependency lane;
- stable dependency lane;
- extension-present/extension-absent capability tests;
- Linux process integration lane;
- Windows portable/network lane where supported.

Do not hide platform-specific failures behind broad test skips. Capability-dependent tests should state exactly why they are skipped.

---

# 37. Public API discipline

Keep 1.0 public API deliberately small.

Likely stable public areas:

```text
Runwire\Runtime
Runwire\Server / Listener
Runwire\Loop contract
Runwire\Connection
Runwire\Protocol contract
Runwire\Http\ProtocolVersion / version-neutral HTTP transport contract
Runwire\Supervisor
Runwire\WorkerGroup / worker lifecycle values
Runwire\Process\Command
Runwire\Process\ProcessRunner
Runwire\Process\ProcessResult
Runwire\RuntimeCapabilities
```

Avoid exposing internal poller registries, parser state machines, PID maps or restart queues as public API.

Prefer composition over dozens of configuration interfaces.

---

# 38. Documentation

Before 1.0 release document:

- architecture/lifetime model;
- native TCP server quick start;
- native HTTP/1.1 + HTTP/2 + Webrick integration;
- HTTP/2 ALPN, stream/flow-control/HPACK/security-limit tuning;
- HTTP/2 graceful GOAWAY/drain and abuse-protection behavior;
- Foundation 3 serving model;
- Omnibus worker-pool integration;
- structured process runner;
- shell-safety rules;
- fork-safety/pre-fork clean-parent rule;
- graceful reload/shutdown;
- event-loop backends;
- TLS;
- connection/backpressure tuning;
- runtime status/control;
- capability matrix;
- trusted-worker vs untrusted-code boundary;
- recommended Foundation supervisor/worker/sandbox deployment profiles;
- performance benchmark methodology.

---

# 39. Foundation launch sequence

Recommended cross-repo execution order:

```text
1. Runwire process + supervisor primitives
2. Runwire loop + TCP/TLS connection layer
3. Version-neutral HTTP transport contract + HTTP/1.1 engine
4. Webrick RunwireRuntimeAdapter against the common HTTP transport
5. HTTP/2 frame/stream/HPACK/flow-control engine + TLS ALPN
6. HTTP/1.1↔HTTP/2 Webrick parity + protocol abuse/fault acceptance
7. Foundation native serve integration
8. Runwire/Omnibus process-supervision integration
9. Pathwise/ReqShield boundary docs/tests alignment
10. aggregate security + persistent-runtime acceptance
11. HTTP/1.1 + HTTP/2 performance comparison + tuning
12. Runwire 1.0 release
13. Foundation 3 final release acceptance
```

The Foundation integration may develop against `dev-main@dev`/development branch only while Runwire 1.0 is unreleased; final Foundation 3 release must consume a released `^1.0` constraint.

---

# 40. Non-goals for Runwire 1.0

Do not expand the launch scope into:

- a DI container;
- an MVC framework;
- an ORM/database layer;
- a cache layer;
- a queue/event bus;
- application scheduler semantics;
- application auth/session;
- request validation;
- storage/upload library;
- template engine;
- custom coroutine ecosystem;
- distributed cluster orchestrator;
- service discovery platform;
- Kubernetes replacement;
- arbitrary remote shell;
- fake PHP sandbox;
- plugin marketplace/runtime.

Those can integrate above/beside Runwire where appropriate.

---

# 41. Runtime drivers, host engines & OPcache

## 41.1 Runtime selection model

Runwire must support these runtime driver values:

```text
auto
native
fpm
frankenphp
swoole
roadrunner
```

Optional future values may be added without changing the application-facing request/lifecycle contract.

### Meaning

- `native` — Runwire owns listener sockets, event loop, HTTP wire transport, worker supervision and process lifecycle using native PHP/OS facilities.
- `fpm` — PHP-FPM owns FastCGI/process-pool/request dispatch; Runwire operates as a request-bound runtime/lifecycle adapter and must not start a competing listener/event loop/supervisor.
- `frankenphp` — FrankenPHP owns its server/thread/worker runtime; Runwire adapts Foundation/Webrick execution to classic or worker mode and preserves request cleanup/isolation.
- `swoole` — Swoole/OpenSwoole owns its event loop, server sockets and worker topology; Runwire registers/adapts lifecycle and request callbacks instead of nesting the Runwire native loop.
- `roadrunner` — RoadRunner owns the external application server and worker management; Runwire adapts the PHP worker lifecycle/request transport and must not create a second HTTP listener/supervisor.
- `auto` — detect the active/available host deterministically and select the safest supported driver according to the precedence policy below.

Runwire must not pretend these engines have identical capabilities. Each driver exposes a capability snapshot.

---

## 41.2 OPcache is orthogonal

Do **not** add `opcache` to the runtime-driver enum.

OPcache is an execution accelerator that can be enabled with any compatible runtime.

Expose a separate policy:

```text
auto
on
off
required
```

Recommended configuration shape:

```php
$runtime = Runtime::create(
    driver: RuntimeDriver::AUTO,
    opcache: OpcacheMode::AUTO,
);
```

or equivalent immutable options.

Semantics:

- `auto` — use OPcache when the host PHP configuration already enables it; never fail solely because it is absent.
- `on` — request/recommend enabled operation but report clearly if the active SAPI cannot enable it at runtime; do not silently claim success.
- `off` — Runwire does not require/use OPcache-specific optimization hooks; it must not mutate unrelated host configuration globally.
- `required` — fail during runtime validation/boot when OPcache is unavailable or disabled for the selected SAPI.

Important PHP constraint: OPcache/CLI enablement is primarily `php.ini`/SAPI configuration. Runwire must validate capability, not pretend it can always turn `opcache.enable` or `opcache.enable_cli` on from application code.

For CLI-oriented `native`, `swoole`, and typical RoadRunner PHP workers, documentation must call out `opcache.enable_cli=1` where OPcache is desired. FPM/FrankenPHP follow their host PHP configuration.

---

## 41.3 Public API direction

Keep runtime selection small and explicit.

Preferred direction:

```php
$runtime = Runtime::create(
    driver: RuntimeDriver::FRANKENPHP,
    opcache: OpcacheMode::AUTO,
);

$runtime->serve($handler);
```

Equivalent config-array construction may exist for framework adapters, but the core API should remain typed.

Foundation-facing configuration can map directly to this model:

```php
'runwire' => [
    'runtime' => 'auto',
    'opcache' => 'auto',
];
```

CLI/environment examples:

```bash
php foundation serve --runtime=native
php foundation serve --runtime=frankenphp
php foundation serve --runtime=swoole
php foundation serve --runtime=roadrunner
```

FPM is normally host-launched rather than started by `foundation serve`; Foundation/Runwire should detect or select `fpm` while executing inside FPM instead of spawning an FPM daemon from the application process.

Allow a trusted config/environment value such as:

```text
RUNWIRE_RUNTIME=auto|native|fpm|frankenphp|swoole|roadrunner
RUNWIRE_OPCACHE=auto|on|off|required
```

Exact Foundation environment naming remains Foundation-owned.

---

## 41.4 Driver contract

Introduce one narrow internal/public integration contract rather than branching through the whole codebase.

Conceptual API:

```php
interface RuntimeDriverInterface
{
    public function capabilities(): RuntimeCapabilities;

    public function validate(RuntimeOptions $options): void;

    public function run(RuntimeApplication $application): void;

    public function stop(): void;
}
```

Exact names may change, but the separation is required.

Common application contract should cover:

- startup/boot callback;
- one logical request/exchange callback;
- request/exchange cleanup in `finally`;
- worker/runtime shutdown callback;
- reload/drain signal when the host exposes it;
- health/status metadata where available.

Do not force host-specific request objects into the common application API. Normalize at the driver boundary.

---

## 41.5 Runtime capability model

Every driver must report capabilities instead of relying on runtime-name conditionals throughout consumers.

At minimum:

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
owns_http1_wire
owns_http2_wire
supports_tls_alpn
supports_websocket
supports_opcache
supports_opcache_cli
```

Capabilities describe the active runtime, not marketing assumptions. Runtime probing must be deterministic and testable.

Foundation/Webrick should consume capabilities where behavior genuinely differs; they should not become large `switch ($runtime)` trees.

---

## 41.6 `auto` detection

`auto` must be conservative and deterministic.

Recommended detection order when already executing inside a host runtime:

```text
FrankenPHP host
    ↓
Swoole/OpenSwoole host
    ↓
RoadRunner worker host
    ↓
FPM/FastCGI
    ↓
Runwire native CLI eligibility
    ↓
unsupported / explicit failure
```

Do not select an installed extension merely because it exists. Detection must distinguish **available** from **currently hosted by**.

For an explicit `foundation serve --runtime=...`, explicit user selection overrides auto detection and capability validation must fail fast when the requested driver is unavailable.

Do not silently fall back from an explicitly requested runtime to another runtime in production.

---

## 41.7 Native driver

`native` is Runwire's full first-party server/runtime implementation from the canonical launch plan.

It owns:

- listener bind/accept;
- pure-PHP/select or optional event backend;
- HTTP/1.1 wire parser/serializer;
- HTTP/2 frame/stream/HPACK/flow-control engine;
- TLS ALPN negotiation for `h2` / `http/1.1`;
- connection state/backpressure across HTTP/1.1 and multiplexed HTTP/2;
- prefork/process supervisor where supported;
- wait/reap/signals/reload;
- generic supervised tasks;
- structured process execution.

On Unix, `pcntl`/`posix` unlock the full prefork/signal model. Capability detection must expose reduced behavior when unavailable instead of hiding it.

`native` must remain usable without Swoole, RoadRunner, FrankenPHP, or FPM.

---

## 41.8 FPM driver

FPM already owns process pools, graceful process management, UIDs/GIDs, FastCGI listeners and per-request dispatch. Runwire must not duplicate those responsibilities.

The FPM driver is intentionally thin:

```text
web server / FastCGI
        ↓
PHP-FPM
        ↓
Runwire FpmDriver
        ↓
Foundation/Webrick request execution
```

Requirements:

- one logical Runwire application execution per FPM request;
- no Runwire long-running event loop;
- no Runwire HTTP socket listener;
- no Runwire prefork worker supervisor;
- request cleanup always executes;
- Runwire process-execution APIs remain independently usable where policy permits;
- transport/runtime capability snapshot clearly marks persistent application state as false for ordinary FPM request mode;
- if an upstream web server terminates HTTP/2 before FastCGI, `supports_http2` may describe end-to-end deployment capability while `owns_http2_wire` remains false for the FPM driver.

This lets an application use Runwire APIs consistently without requiring the Runwire-native server.

---

## 41.9 FrankenPHP driver

Support both host shapes when detectable:

```text
classic mode
worker mode
```

### Classic mode

Treat request/application persistence similarly to a request-bound SAPI integration.

### Worker mode

FrankenPHP keeps application code resident and repeatedly invokes a worker handler. Runwire must therefore enforce the persistent-runtime contract:

- boot long-lived application state once where appropriate;
- begin a fresh Foundation/Webrick execution scope per request;
- cleanup request-scoped state in `finally`;
- never retain request/auth/session/DB execution state across requests;
- integrate worker restart/reload hooks when exposed;
- do not start a nested Runwire event loop or process pool;
- preserve host-owned threads/workers;
- report HTTP/1.1/HTTP/2 capability separately from wire ownership; FrankenPHP may terminate HTTP/2 itself while Runwire adapts the resulting request rather than reparsing frames.

Runwire must document that globals, statics and in-memory state can persist in worker mode and therefore application/framework reset discipline is mandatory.

FrankenPHP remains an optional host integration; Runwire must not depend on the FrankenPHP binary for normal installation.

---

## 41.10 Swoole/OpenSwoole driver

Swoole/OpenSwoole owns its server, event loop, workers and coroutine system. Runwire must adapt rather than compete.

Requirements:

- bind Runwire application callback to the host HTTP/request event;
- normalize native request/response into the common Runwire transport contract;
- map start/worker-start/worker-stop/shutdown/reload lifecycle events;
- preserve fresh application execution scope per logical request;
- never run the Runwire native `stream_select()` loop inside the Swoole server loop;
- never create a parallel Runwire prefork supervisor for host HTTP workers;
- expose coroutine/async capability without requiring Foundation to become coroutine-coupled;
- document and test persistent static/global state isolation;
- report HTTP/2 support/wire ownership truthfully according to the active Swoole/OpenSwoole server path instead of nesting the native Runwire HTTP/2 engine.

Support may target Swoole/OpenSwoole through capability adapters; exact package/extension compatibility should be isolated from the core runtime API.

---

## 41.11 RoadRunner driver

RoadRunner owns the external server/process manager and dispatches work to PHP workers.

Required architecture:

```text
RoadRunner server
      ↓
RR PHP worker transport
      ↓
Runwire RoadRunnerDriver
      ↓
Foundation/Webrick execution
```

Requirements:

- use the official RoadRunner PHP worker/protocol ecosystem where practical rather than cloning Goridge/worker transport;
- one fresh application execution scope per request/job exchange;
- application/container reuse only where Foundation's persistent-runtime contract permits it;
- cleanup in `finally`;
- map worker stop/recycle/reset behavior into generic Runwire lifecycle signals;
- do not bind a second HTTP listener;
- do not fork a second HTTP worker pool underneath RoadRunner;
- keep RoadRunner packages optional/suggested unless selected adapter code intrinsically requires a separate integration package;
- distinguish HTTP/2 accepted/terminated by the RoadRunner front server from Runwire native HTTP/2 wire ownership.

---

## 41.12 Unified option passing

The user's selected driver should change **hosting behavior**, not application APIs.

Example:

```php
Runwire::boot([
    'runtime' => 'roadrunner',
    'opcache' => 'required',
    'workers' => 8,
    'max_requests' => 10_000,
]);
```

But options must be partitioned by ownership:

### Portable options

- request timeout;
- max requests before recycle preference;
- application drain timeout;
- status/diagnostics policy;
- OPcache requirement;
- common transport bounds that Runwire can enforce at its boundary.

### Native-only options

- listener backlog;
- Runwire event-loop backend;
- native worker count;
- Runwire restart budget;
- Runwire socket high/low watermarks;
- native prefork mode.

### Host-driver options

Host-specific settings must live under a namespaced section rather than polluting the common option namespace:

```php
[
    'runtime' => 'frankenphp',
    'frankenphp' => [
        // adapter-specific knobs only
    ],
    'swoole' => [
        // adapter-specific knobs only
    ],
    'roadrunner' => [
        // adapter-specific knobs only
    ],
]
```

Runwire must reject irrelevant/unknown strict-production options rather than silently ignoring a `native` setting while running under another host.

Do not copy every host server's complete configuration DSL into Runwire. Expose only integration-relevant options; native host configuration remains authoritative for host-owned mechanics.

---

## 41.13 Foundation integration

Foundation should expose one runtime selector and keep its application graph independent of the selected host.

Target model:

```text
Foundation application
        ↓
Webrick HTTP semantics
        ↓
Runwire Runtime
        ↓
selected driver
 ┌────────┬────────────┬────────┬────────────┬──────────┐
 native    FPM       FrankenPHP  Swoole     RoadRunner
```

Foundation owns:

- config/env/CLI selection;
- whether explicit runtime selection is allowed in production;
- release-generation mapping;
- application boot and execution scopes;
- process capability authorization;
- runtime-specific deployment documentation/defaults.

Runwire owns runtime detection/driver mechanics/capabilities.

Webrick should need only the Runwire transport/runtime adapter for the Foundation native path; host differences stay below that integration where possible.

---

## 41.14 Testing matrix

Runwire 1.0 release acceptance should add driver-specific tests.

### Common contract

- same application handler semantics across all available drivers;
- HTTP/1.1/HTTP/2 capability reporting distinguishes end-to-end support from Runwire wire ownership;
- startup/request/shutdown ordering;
- guaranteed request cleanup;
- structured runtime capabilities;
- explicit unavailable-driver failure;
- explicit selection never silently falls back;
- auto detection is deterministic;
- unknown/irrelevant option rejection;
- OPcache `required` fails closed when unavailable.

### Persistent runtimes

For FrankenPHP worker mode, Swoole and RoadRunner:

- repeated requests do not retain prior request state;
- interleaved/concurrent execution follows driver-supported isolation semantics;
- DB/cache/network resources obey application execution ownership;
- memory growth/recycle behavior is bounded/measured;
- worker reload/recycle does not corrupt release/application state.

### FPM

- no persistent application/request state assumption;
- no native listener/event-loop/supervisor starts;
- request cleanup and process-execution APIs remain correct.

### Native

Keep the full native network/supervisor/backpressure/security acceptance from the canonical plan.

---

## 41.15 Benchmark matrix

Record separate measurements for:

```text
FPM
FrankenPHP classic
FrankenPHP worker
Swoole/OpenSwoole
RoadRunner
Runwire native/select
Runwire native/optional event backend
```

For each available environment measure:

- cold start/boot;
- warm request throughput over HTTP/1.1 and HTTP/2 where supported;
- HTTP/2 multiplexing behavior at multiple concurrent-stream levels;
- p50/p95/p99 latency;
- memory per worker/process/thread where measurable;
- persistent memory growth;
- request cleanup overhead;
- Runwire adapter overhead versus direct host-framework integration;
- OPcache on/off effect where the host permits a meaningful controlled comparison.

Do not combine these into one misleading headline benchmark. Attribute host runtime cost versus Runwire adapter cost.

---

## 41.16 Dependency policy

Core Runwire must remain installable for the native/FPM baseline without requiring all optional runtimes.

Recommended policy:

- Swoole/OpenSwoole: optional extension capability.
- FrankenPHP: optional host capability, no mandatory Composer dependency merely for detection.
- RoadRunner: optional suggested/reference worker package(s) where needed by the driver.
- OPcache: optional Zend extension/capability, not a Composer dependency.
- FPM: SAPI/host capability, not a Composer dependency.

The core package should fail only when the caller explicitly selects a runtime whose required host capability is unavailable.

---

## 41.17 Completion gate extension

Runwire 1.0/Foundation 3 launch additionally requires:

- [ ] runtime enum/config supports `auto`, `native`, `fpm`, `frankenphp`, `swoole`, `roadrunner`;
- [ ] OPcache is modeled separately as `auto|on|off|required`;
- [ ] capability detection distinguishes installed from actively hosted runtime;
- [ ] explicit runtime selection fails fast rather than silently falling back;
- [ ] native mode remains a complete first-party HTTP/1.1 + HTTP/2 server implementation;
- [ ] native TLS mode negotiates `h2` / `http/1.1` through ALPN;
- [ ] host-driver capability reporting distinguishes `supports_http2` from `owns_http2_wire`;
- [ ] host modes do not start competing event loops/listeners/process pools;
- [ ] FPM request-bound behavior is tested;
- [ ] FrankenPHP classic + worker-mode lifecycle is documented/tested where available;
- [ ] Swoole/OpenSwoole persistent lifecycle is documented/tested where available;
- [ ] RoadRunner worker lifecycle is documented/tested where available;
- [ ] persistent runtime state isolation passes across all persistent drivers;
- [ ] common option parsing and host-specific namespaced options are bounded/validated;
- [ ] benchmarks attribute Runwire adapter overhead separately for every supported host;
- [ ] Foundation can select the runtime through trusted config/CLI without changing Webrick application semantics.

This section is part of the canonical Runwire 1.0 launch gate.

---

# 42. Future plan after Runwire 1.0

The following items are intentionally outside the Runwire 1.0 / Foundation 3 launch gate. They must not leak into current 1.0 capability promises, completion criteria, or implementation blockers.

## 42.1 HTTP/3 / QUIC

HTTP/3 is the next native HTTP protocol target after Runwire 1.0 stabilizes HTTP/1.1 and HTTP/2.

Future ownership remains consistent:

```text
HTTP/1.1 -> TCP/TLS -> Runwire HTTP/1 engine
HTTP/2   -> TCP/TLS -> Runwire HTTP/2 engine
HTTP/3   -> QUIC/UDP/TLS 1.3 -> future Runwire HTTP/3 engine
```

The future HTTP/3 implementation must normalize into the same version-neutral Runwire HTTP transport consumed by Webrick so Foundation application semantics do not change.

Future HTTP/3 work should cover, after a dedicated design/review pass:

- QUIC transport over UDP rather than pretending HTTP/3 is another TCP framing layer;
- TLS 1.3 handshake and QUIC cryptographic integration through a mature, supportable implementation path;
- bidirectional and unidirectional QUIC stream lifecycle;
- HTTP/3 control streams and SETTINGS;
- QPACK encoder/decoder and blocked-stream accounting;
- connection-level and stream-level flow control/backpressure;
- connection IDs, migration/rebinding policy where supported;
- graceful connection drain and GOAWAY semantics;
- cancellation/reset/STOP_SENDING handling;
- 0-RTT policy and replay-safety boundaries;
- bounded QPACK dynamic-table/header-list state;
- stream-count, control-frame and CPU/work amplification limits;
- QUIC/HTTP/3 abuse/flood resistance and memory ceilings;
- optional HTTP Datagrams/WebTransport only through later explicit capability work;
- future `supports_http3` / `owns_http3_wire` capability reporting only once implemented and production-ready;
- host-driver passthrough semantics when FrankenPHP, RoadRunner or another host terminates HTTP/3 outside Runwire;
- HTTP/1.1 / HTTP/2 / HTTP/3 Webrick application-semantic parity;
- dedicated interoperability, soak and benchmark suites.

Do not select a QUIC dependency, extension, FFI binding, sidecar, or implementation strategy in the 1.0 plan merely to reserve HTTP/3. That choice requires a separate post-1.0 security, portability, maintenance and performance evaluation.

HTTP/3 must not block Runwire 1.0.

---

# 43. 1.0 completion gate

Runwire 1.0 is release-ready only when all of the following are true:

- [ ] runtime topology is instance-owned and freezeable;
- [ ] select-based event loop is correct and bounded;
- [ ] optional faster loop backend has parity if shipped;
- [ ] TCP server/connection lifecycle is production-safe;
- [ ] output/input backpressure is proven under slow-client tests;
- [ ] HTTP/1.1 transport passes framing/smuggling/slow-client limits;
- [ ] HTTP/2 transport passes RFC 9113 framing/stream/SETTINGS/GOAWAY/flow-control acceptance;
- [ ] HPACK is bounded, connection-local and passes malformed/Huffman/compression-amplification tests;
- [ ] HTTP/2 Rapid Reset-style stream churn, control-frame floods and CONTINUATION/header-block abuse remain bounded;
- [ ] multiplexed streams preserve independent backpressure, cancellation and Foundation request state;
- [ ] native TLS ALPN negotiates HTTP/2/HTTP/1.1 correctly;
- [ ] HTTP/1.1 and HTTP/2 requests have Webrick application-semantic parity;
- [ ] Webrick native Runwire adapter passes parity tests;
- [ ] Foundation can serve its compiled web runtime through Runwire with a fresh application execution scope per request;
- [ ] trusted prefork supervision handles restart/reload/shutdown/reaping without zombies;
- [ ] Foundation app resources are created after fork in children, not leaked from master;
- [ ] structured ProcessRunner has no implicit shell path and enforces configured bounds;
- [ ] timeout/output/deadlock process tests pass;
- [ ] Omnibus process-worker supervision can delegate generic OS process mechanics to Runwire without moving queue semantics;
- [ ] Pathwise and ReqShield boundaries are documented without adding Runwire dependencies to those packages;
- [ ] untrusted-code guidance explicitly requires a separate runtime/OS isolation boundary;
- [ ] PHP 8.4/8.5 QA/static/security suites are green;
- [ ] soak/fault tests show no unbounded memory/FD/child-state growth;
- [ ] benchmark evidence against Workerman is recorded without benchmark-only shortcuts;
- [ ] Foundation 3 final release consumes released `infocyph/runwire:^1.0`.

---

# 44. Immediate implementation handoff

Start with the process/runtime skeleton, not HTTP conveniences:

```text
RuntimeCapabilities
    ↓
Loop contract + SelectLoop
    ↓
Supervisor + WorkerGroup + child lifecycle
    ↓
Process Command/Runner + bounded pipe I/O
    ↓
TCP/TLS Listener + Connection + backpressure
    ↓
Version-neutral HTTP transport + HTTP/1.1
    ↓
Webrick adapter contract
    ↓
HTTP/2 frames + streams + HPACK + flow control + ALPN
    ↓
HTTP/1.1 / HTTP/2 parity + abuse acceptance
    ↓
Foundation native server
```

The first implementation milestone should prove two independent uses before broadening the API:

1. a supervised multi-worker TCP echo + HTTP/1.1 fixture;
2. the same Webrick handler exercised over native HTTP/2 with multiplexed streams and bounded flow control; and
3. a structured bounded child command execution fixture.

That validates both halves of Runwire's identity—the server runtime and the former ProcessGuard process-security/runtime scope—without pulling Foundation-specific behavior into the package.

---
