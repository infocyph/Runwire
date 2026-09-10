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
- HTTP/1.1 wire parsing/serialization required for a native HTTP server;
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

# 10. HTTP/1.1 wire protocol for Foundation/Webrick

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

## 10.2 HTTP/2

HTTP/2 is not required for Runwire 1.0/Foundation 3 launch unless implementation evidence shows it can be completed without delaying correctness/security acceptance.

Design protocol contracts so HTTP/2 can be added later without changing Webrick application semantics.

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
Runwire HTTP/1 connection/parser
        ↓
Runwire HTTP request transport object
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
- keep-alive HTTP;
- small dynamic Webrick route;
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

Do not make public “faster than Workerman” claims unless repeatable measurements support them.

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
- HEAD semantics at transport boundary coordinated with Webrick;
- Webrick adapter parity against SAPI/Workerman adapters.

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

- sustained keep-alive HTTP traffic;
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
- native HTTP + Webrick integration;
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
2. Runwire loop + TCP connection layer
3. Runwire HTTP/1 transport
4. Webrick RunwireRuntimeAdapter
5. Foundation native serve integration
6. Runwire/Omnibus process-supervision integration
7. Pathwise/ReqShield boundary docs/tests alignment
8. aggregate security + persistent-runtime acceptance
9. performance comparison + tuning
10. Runwire 1.0 release
11. Foundation 3 final release acceptance
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

# 41. 1.0 completion gate

Runwire 1.0 is release-ready only when all of the following are true:

- [ ] runtime topology is instance-owned and freezeable;
- [ ] select-based event loop is correct and bounded;
- [ ] optional faster loop backend has parity if shipped;
- [ ] TCP server/connection lifecycle is production-safe;
- [ ] output/input backpressure is proven under slow-client tests;
- [ ] HTTP/1.1 transport passes framing/smuggling/slow-client limits;
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

# 42. Immediate implementation handoff

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
TCP Listener + Connection + backpressure
    ↓
HTTP/1 transport
    ↓
Webrick adapter
    ↓
Foundation native server
```

The first implementation milestone should prove two independent uses before broadening the API:

1. a supervised multi-worker TCP echo/HTTP fixture; and
2. a structured bounded child command execution fixture.

That validates both halves of Runwire's identity—the server runtime and the former ProcessGuard process-security/runtime scope—without pulling Foundation-specific behavior into the package.