# Runwire 1.0 Architecture and Runtime Contracts

Runwire is a framework-agnostic process, event-loop, network, HTTP, and structured-concurrency runtime. Its public contract is capability-first: integrations should ask what the selected runtime can do instead of assuming behavior from a concrete driver name.

## 1. Ownership boundaries

Runwire owns low-level runtime mechanics:

```text
process supervision
worker lifecycle
listener/event-loop integration
TCP / Unix stream / UDP transport
TLS
HTTP/1.1 / HTTP/2 / HTTP/3
HPACK / QPACK
request context / cancellation / deadlines
bounded admission and backpressure
structured coroutines
runtime metrics / diagnostics
```

Runwire does not own framework/application concerns such as routing, dependency injection, ORM behavior, templating, application configuration, business-domain conventions, or a message broker.

Within the Infocyph stack:

```text
Runwire     process/network/runtime substrate
Foundation  application/container semantics
Webrick     higher-level application HTTP semantics
Omnibus     messaging/queue semantics
```

Those boundaries are architectural constraints, not just package organization.

## 2. Runtime selection

`RuntimeOptions` defaults to `RuntimeDriver::AUTO`.

Automatic host precedence is:

```text
FrankenPHP
→ Swoole/OpenSwoole
→ RoadRunner
→ FPM
→ native CLI
```

Hosted runtimes are selected only when the current process is actually hosted by them. Native execution requires CLI SAPI.

An explicit driver request must be available in the current environment or startup fails.

## 3. Native execution topologies

Native Runwire always owns the listener and event loop. Worker-pool ownership depends on process capabilities.

### Native prefork

Available when CLI plus the required PCNTL/POSIX process capabilities are present.

Runwire owns:

- listener binding;
- event loops;
- worker pool;
- readiness and process supervision;
- rolling reload;
- worker recycle/replacement;
- native control operations;
- development reload watcher;
- optional worker UID/GID privilege reduction.

Worker privilege reduction is completed before application bootstrap/readiness. UID-based drops resolve the target account, normalize supplementary groups, set the primary GID before UID, and verify the final effective identity. Any incomplete transition is a startup failure.

Native prefork selected as effective UID `0` without a configured worker privilege-drop policy emits a `SECURITY:` runtime-selection warning. Production applications should normally run under an unprivileged service identity.

### Native portable

Used when native CLI is available but prefork process capabilities are not.

Runwire still owns:

- native listeners;
- one shared `SelectLoop`;
- HTTP/1.1/HTTP/2 processing;
- framed TCP/Unix servers;
- UDP servers;
- HTTP/3 when QUIC capability is present;
- persistent application lifecycle;
- request contexts, cancellation, admission, diagnostics, and coroutines.

It does **not** advertise:

- worker-pool ownership;
- graceful worker reload;
- worker replacement/recycle capability;
- supervisor lifecycle events;
- supervisor control socket;
- development worker watching;
- prefork privilege-drop boundary.

The portable contract is fail-closed:

```text
workers 0 or 1            one portable process
workers > 1               startup error
enabled recycle threshold startup error
control endpoint           startup error
development watcher        startup error
lifecycle listener         startup error
worker privilege drop      startup error
HTTP/3 without QUIC        startup error
```

External supervision owns process replacement for portable deployments.

## 4. Hosted execution

Hosted runtimes own their network/runtime boundary. Runwire must not start a competing listener, event loop, or worker pool.

Use:

```php
Runtime::create()->serve($handler);
```

or:

```php
Runtime::create()->serveApplication($factory);
```

Do not combine `listen()` with `serve()`.

### FPM

From Runwire's application boundary, FPM is request-scoped. The external web server/FPM pool owns process and wire lifecycle.

### FrankenPHP

FrankenPHP owns the host server. Persistent application behavior depends on worker/classic mode and resolved capabilities.

### RoadRunner

RoadRunner owns the worker/server transport. Runwire adapts the application lifecycle inside the host.

### Swoole/OpenSwoole

Swoole/OpenSwoole owns the reactor/server. Runwire can bridge its `LoopInterface` and structured Fiber scheduler to the host reactor without replacing Runwire task semantics with a second public coroutine model.

## 5. Capability model

Generic integration code should use `RuntimeContext::supports()` or `requireCapability()`.

```php
use Infocyph\Runwire\Runtime\Enum\RuntimeCapability;

if ($context->supports(RuntimeCapability::PERSISTENT)) {
    // Keep worker-lifetime state only when the runtime permits it.
}

if ($context->supports(RuntimeCapability::SUPPORTS_HTTP3)) {
    // Runtime-level HTTP/3 capability is available.
}

$context->requireCapability(RuntimeCapability::RUNWIRE_COROUTINES);
```

Important capabilities include:

```text
PERSISTENT
CONCURRENT
OWNS_LISTENER
OWNS_EVENT_LOOP
OWNS_WORKER_POOL
RUNWIRE_LOOP_AVAILABLE
RUNWIRE_COROUTINES
HOST_NATIVE_COROUTINES
HOST_OWNS_EVENT_LOOP
SUPPORTS_GRACEFUL_RELOAD
SUPPORTS_WORKER_RECYCLE
SUPPORTS_HTTP1
SUPPORTS_HTTP2
SUPPORTS_HTTP3
```

Driver-name branching is appropriate only for truly host-specific APIs.

## 6. RuntimeContext

`RuntimeContext` is immutable application/worker-lifetime metadata.

It exposes:

```text
driver
mode
worker slot
generation
PID
persistent/concurrent flags
listener/event-loop/worker-pool ownership
resolved RuntimeCapabilities
RuntimeMetrics
```

Example:

```php
$context->driver;
$context->mode;
$context->workerSlot;
$context->generation;
$context->pid;
$context->persistent;
$context->concurrent;
$context->snapshot();
```

Do not store request-specific data on `RuntimeContext`.

## 7. RequestContext

`RequestContext` owns one logical request's runtime state:

```text
request ID
monotonic start time
request deadline
cancellation token
bounded request-local attributes
owning RuntimeContext
```

Example:

```php
$request->context->setAttribute('trace_id', $traceId);

$traceId = $request->context->attribute('trace_id');

if ($request->context->cancelled()) {
    return;
}

$request->context->cancellation->throwIfCancelled();
```

Attribute keys are bounded and each request context has a fixed attribute-count ceiling. `complete()` clears request-local attributes and disposes request-owned cancellation state.

A stored `null` value is distinct from a missing attribute because lookup uses key existence rather than `isset()` semantics.

## 8. Deadlines and cancellation

Runwire uses monotonic time for elapsed/deadline calculations.

Cancellation is cooperative. Sources include:

- request deadline expiry;
- transport cancellation;
- host cancellation;
- worker/runtime shutdown;
- structured parent cancellation;
- task-group failure policy.

A cancellation token communicates intent; it does not asynchronously interrupt arbitrary PHP code.

Long CPU loops should checkpoint:

```php
$request->context->cancellation->throwIfCancelled();
```

Structured coroutine work should use:

```php
$scope->cancellation()->throwIfCancelled();
$scope->yieldNow();
```

HTTP/2 and HTTP/3 stream cancellation is stream-local and must not cancel unrelated sibling streams.

## 9. Application lifecycle

Managed applications follow this lifecycle:

```text
create
→ boot
→ warmup
→ ready
→ handle
→ reset
→ handle
→ reset
→ drain
→ shutdown
```

Boot/warmup happen before readiness. Request reset executes after each request, including failure paths. Drain stops optional/new application work while admitted work completes within configured bounds. Shutdown is final for that application instance.

Frameworks can supply `RuntimeApplicationFactoryInterface` so construction happens after the runtime context is known.

```php
final class Factory implements RuntimeApplicationFactoryInterface
{
    public function create(RuntimeContext $context): RuntimeApplicationInterface
    {
        $context->requireCapability(RuntimeCapability::PERSISTENT);

        return new RuntimeApplication(
            $handler,
            runtimeContext: $context,
        );
    }
}
```

Factories are the preferred place to validate framework-specific assumptions.

## 10. HTTP architecture

All native HTTP versions normalize into the same application-facing pair:

```text
HttpRequest
ResponseWriterInterface
```

Protocol-specific responsibilities remain below the application boundary:

```text
HTTP/1.1 parsing / keep-alive
HTTP/2 frames / streams / HPACK / flow control
HTTP/3 frames / streams / QPACK / QUIC transport
```

The application should not need to implement protocol framing to respond to a request.

## 11. TLS and HTTP/2

`TcpListener` validates that OpenSSL is available before binding a configured TLS listener.

`TlsOptions` defaults ALPN to:

```text
h2
http/1.1
```

TLS configuration is explicit. Missing OpenSSL is a startup error for a TLS listener, not a plaintext fallback.

## 12. HTTP/3 and QUIC

HTTP/3 is optional and capability-gated.

The current native adapter uses `ext-quic` and requires the supported QUIC/OpenSSL baseline. Runwire delegates cryptography, congestion control, packet recovery, and transport mechanics to the native QUIC engine.

Runwire owns the HTTP/3 application/runtime layer:

```text
HTTP/3 framing
QPACK integration
stream lifecycle
request normalization
response dispatch
drain / GOAWAY behavior
resource ceilings
```

0-RTT application dispatch is disabled for 1.0.

Explicit HTTP/3 configuration without a supported QUIC capability is a startup error; it is never silently ignored. When HTTP/3 is not configured, absence of QUIC does not affect HTTP/1.1 or HTTP/2.

Peer address changes caused by QUIC rebinding/migration must not be used as an authentication boundary.

## 13. Network architecture

### Byte-stream core

`Network\Connection` owns non-blocking transport state, buffers, close reasons, backpressure, and loop callbacks.

### Framed protocols

`StreamServer` combines a TCP/Unix connection with a `FrameCodecInterface` and `FramedConnection`.

A codec must remain incremental and bounded. `LineCodec` is the built-in delimiter-framing implementation.

### UDP

`DatagramServer` / `DatagramListener` provide bounded UDP receive/send handling. UDP has no stream ordering, delivery guarantee, or connection lifecycle; application protocols must account for that explicitly.

## 14. Event-loop architecture

`LoopInterface` is the runtime abstraction for:

```text
deferred callbacks
timers
repeating timers
read readiness
write readiness
cancellation
monotonic time
run / stop
```

`SelectLoop` is the built-in portable loop and fallback implementation.

Optional host/custom loop integrations must preserve the same contract. Runwire does not ship a second independent timer reactor for coroutines.

## 15. Structured concurrency architecture

The coroutine layer is:

```text
PHP Fiber
+ FiberScheduler
+ LoopInterface
+ CoroutineScope ownership
```

Normal ownership:

```text
runtime
├── request root scope
│   └── nested task groups
│       └── tasks
└── worker background scope
    └── tasks
```

Callbacks from timers, I/O readiness, cancellation, or futures enqueue readiness; they do not recursively resume Fibers inline.

There is no unrestricted request-level detached-task escape hatch. Work that outlives one request belongs to worker-generation background ownership.

## 16. Resource model

Runwire is designed around bounded state.

Protection layers include:

- listener connection ceiling;
- per-worker admission limits;
- request-body/header limits;
- HTTP/2/HTTP/3 stream limits;
- pending response bytes;
- HPACK/QPACK limits;
- event-loop work budgets;
- coroutine task/backlog/waiter limits;
- fixed-cardinality diagnostics;
- bounded restart/reload/recycle policy.

Increasing a limit increases retained state. Treat limit changes as capacity planning, not merely configuration convenience.

`WorkerRecyclePolicy::maxMemoryBytes` uses PHP allocator memory (`memory_get_usage(true)` / `memory_get_peak_usage(true)`), not process RSS or cgroup/container memory. OS/container limits remain the hard process-memory boundary.

## 17. Graceful lifecycle

Native graceful shutdown follows the same broad sequence across prefork and portable execution:

```text
stop accepting
→ application drain
→ protocol drain / GOAWAY where applicable
→ finish admitted work within deadline
→ force remaining work at deadline
→ close runtime resources
```

Prefork adds process replacement/reaping. Portable mode drains attachments on one process/shared loop.

## 18. Observability

Runtime observability is fixed-cardinality by design.

Metrics/diagnostics cover:

```text
requests / failures
connections / bytes
streams
backpressure / overload
worker state / age / busy time
event-loop health
coroutine scheduler state
protocol-specific state
```

Do not retain unbounded per-request/per-connection history in runtime status structures.

Operational concepts are intentionally distinct:

```text
live      control/runtime path functions
ready     can admit work
healthy   no configured health failure
draining  intentionally refusing new work while finishing admitted work
```

A PID existing is not sufficient evidence of readiness or health.

## 19. Security contract

Runwire favors explicit failure over security-sensitive downgrade.

Examples:

- TLS never silently becomes plaintext;
- explicit HTTP/3 without QUIC fails startup;
- unsupported privilege drop fails;
- control sockets require bounded requests/responses and restrictive permissions;
- `SO_REUSEPORT` is explicit and off by default;
- 0-RTT application dispatch is disabled;
- diagnostics should not expose arbitrary request bodies, headers, argv, or environment data.

Runwire is not an OS sandbox. See [Runtime security and production hardening](security.md) for least privilege, persistent-state cleanup, ProcessRunner policy, resource ceilings, `disable_functions`, and systemd/container hardening.

## 20. Public integration rule

The most important integration rule is:

> Depend on capabilities and lifecycle contracts; use driver-specific code only for genuinely driver-specific features.

That rule keeps Foundation, Webrick, Omnibus, and third-party consumers portable across native and host-owned runtimes.

## Related documentation

- [Getting started](getting-started.md)
- [Deployment and operations](deployment.md)
- [Runtime security and production hardening](security.md)
- [Coroutines and structured concurrency](coroutines.md)
- [Benchmark methodology](benchmarks.md)
- [Runwire 1.0 launch plan](plans/runwire-1.0-foundation-3-launch-plan.md)
