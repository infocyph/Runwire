# Runwire 1.0 Deployment and Tuning

Runwire is a framework-agnostic process and network runtime. It can own native HTTP wire handling or adapt an application to an existing PHP host runtime. These modes have different ownership and deployment requirements and should not be mixed inside one process.

## Runtime ownership and capability matrix

| Mode | Listener / wire owner | Persistent application | Concurrent requests | Runwire worker pool | Graceful reload/recycle |
| --- | --- | --- | --- | --- | --- |
| `native` | Runwire | yes | protocol/application dependent | yes | yes |
| `fpm` | PHP-FPM / web server | no from Runwire's request boundary | host-managed | no | host-managed |
| `frankenphp` worker | FrankenPHP | yes | host capability dependent | no | supported through host/runtime contract |
| `swoole` / `openswoole` | Swoole/OpenSwoole | yes | host capability dependent | no | host-native plus Runwire generic policy where supported |
| `roadrunner` | RoadRunner | yes | host/session dependent | no | supported through host/runtime contract |
| `auto` | detected environment | capability-derived | capability-derived | capability-derived | capability-derived |

Hosted modes must not start a competing Runwire listener, event loop, worker pool or HTTP server. Framework integrations should query `RuntimeContext::supports()` / `requireCapability()` instead of switching on a driver name for generic behavior.

Important capability facts include persistence, concurrency, listener ownership, event-loop ownership, worker-pool ownership, HTTP/2, HTTP/3, graceful reload and worker recycle. Host-specific integration code may still use a concrete host adapter when it genuinely needs host-native APIs.

## RuntimeContext and RequestContext

`RuntimeContext` is immutable for one application/worker lifetime and exposes bounded runtime facts such as:

```text
driver / mode
worker slot / generation / pid
persistent / concurrent
listener / event-loop / worker-pool ownership
runtime capabilities
metrics provider
```

`RequestContext` is one logical request/stream's isolated execution state. It contains a bounded request ID, monotonic start time, request deadline, cancellation token, bounded request-local attributes and a reference to the owning `RuntimeContext`.

Request-local attributes are cleared at completion. They are intended for small execution metadata such as trace IDs, scoped auth metadata, locale or request-local cache references—not arbitrary unbounded process storage.

### Cancellation and deadlines

Cancellation is cooperative and request-local. Sources include transport cancellation, host cancellation, deadline expiry and worker shutdown. HTTP/2/HTTP/3 stream cancellation does not require a process-global alarm and does not cancel sibling streams.

`RuntimeOptions::requestExecution` controls the generic request deadline. `null`/disabled means no Runwire request execution deadline, but outer worker/server drain deadlines still apply. All elapsed/deadline calculations use monotonic time.

Applications should periodically check long-running cooperative work when cancellation responsiveness matters. A cancellation token communicates intent; it is not an unsafe asynchronous interruption mechanism.

## Application factory and lifecycle

Framework/runtime integrations can supply a `RuntimeApplicationFactoryInterface`. The factory receives the resolved `RuntimeContext`, allowing the integration to validate persistence/concurrency/capabilities before it creates worker-local application state.

One managed application instance follows this lifecycle:

```text
boot      once per application/worker instance
warmup    before readiness
handle    for each admitted request
reset     after every request, including failure paths
drain     stop starting optional/new application work
shutdown  exactly once
```

A worker is not ready until required boot/warmup succeeds. Warmup failure is classified separately and participates in bounded supervisor restart/backoff. During a rolling replacement, failed warmup must not force healthy old-generation capacity out of service.

Request resetters are composable. Every registered resetter gets a chance to run even if the handler or an earlier resetter fails. Cleanup failures are reported without retaining stale request state for the next request.

## Native HTTP protocol stack

Runwire 1.0 supports:

- HTTP/1.1 over TCP or TLS;
- HTTP/2 over TLS ALPN (`h2`) with HTTP/1.1 fallback (`http/1.1`);
- HTTP/3 over QUIC v1 / TLS 1.3 with ALPN `h3` when the optional QUIC capability is available.

HTTP/1.1, HTTP/2 and HTTP/3 dispatch the same application-visible `HttpRequest` / `ResponseWriterInterface` contract. Protocol framing, HPACK/QPACK, stream management and transport errors remain runtime concerns.

## HTTP/3 and QUIC

The ordinary Composer installation does not require QUIC. Native HTTP/3 is capability-based and fails fast when selected without a supported QUIC engine.

The Runwire 1.0 CI-backed QUIC adapter uses `mikepultz/php-quic` (`ext-quic`). The current native adapter requires an OpenSSL 3.5+ QUIC-capable baseline. Deployments enabling HTTP/3 must provide:

1. the supported QUIC extension/engine;
2. TLS certificate and private-key material;
3. UDP reachability for the selected listener port;
4. ALPN `h3` capability;
5. resource limits appropriate for expected QUIC connection and stream concurrency.

Runwire delegates QUIC cryptography, congestion control and loss recovery to the maintained native QUIC engine. It does not implement those algorithms in PHP.

### 0-RTT and peer addresses

0-RTT application dispatch is disabled for Runwire 1.0. Replay-unsafe requests are therefore not silently promoted into ordinary trusted application requests.

QUIC connection migration or address rebinding may change peer-address metadata during a connection. Do not use peer-address stability as an authentication or authorization boundary.

## Graceful shutdown and rolling reload

Native shutdown follows a bounded drain model:

1. stop accepting new work;
2. HTTP/2 and HTTP/3 enter drain state and send GOAWAY where applicable;
3. application drain hooks stop optional/new background work;
4. already-admitted requests/streams are allowed to complete within configured bounds;
5. remaining work/connections are terminated according to policy when the deadline expires;
6. supervised children are reaped before shutdown completes.

Rolling reload is controlled by `ReloadPolicy`. The default replacement sequence is:

```text
spawn replacement
→ wait for boot/warmup/readiness
→ mark replacement ready
→ request old worker drain
→ old worker exits
→ continue to the next slot
```

`maxSurge`, `maxUnavailable`, replacement readiness timeout and drain timeout are supervisor policy, not application convention. Groups can be marked non-reloadable when infrastructure/service workers should survive application-code reloads.

If replacement startup/readiness repeatedly fails, rollout stops rather than destructively consuming healthy old capacity. Restart/backoff budgets remain bounded and the failed rollout is visible in status/events.

Drain/shutdown hooks receive a stable reason such as deployment reload, request/memory/lifetime recycle, manual recycle, supervisor stop or fatal runtime failure. Exit/restart diagnostics distinguish planned retirement from crash/startup/readiness failures.

## Worker recycling

Persistent worker recycling is configured through `RuntimeOptions::workerRecycle` using the generic `WorkerRecyclePolicy`. Request-count, lifetime and memory thresholds are soft retirement triggers evaluated at safe request boundaries; they do not kill active application work immediately. Request and lifetime jitter can stagger retirement so workers started together do not all recycle at the same threshold.

Example:

```php
use Infocyph\Runwire\RuntimeOptions;
use Infocyph\Runwire\Supervisor\WorkerRecyclePolicy;

$options = new RuntimeOptions(
    workerRecycle: new WorkerRecyclePolicy(
        maxRequests: 10_000,
        maxLifetimeSeconds: 3_600,
        maxMemoryBytes: 268_435_456,
        jitterRequests: 500,
        jitterSeconds: 120,
        gracefulTimeoutSeconds: 10.0,
    ),
);
```

All recycle thresholds default to disabled. This avoids silently imposing operational limits before an application has measured its workload. FPM is request-scoped from Runwire's point of view and therefore does not pretend to apply persistent-worker recycle dimensions. Swoole/OpenSwoole keeps native request-count enforcement where required while Runwire uses the generic policy contract for supported shared dimensions.

## Admission and overload behavior

`RuntimeOptions::admission` provides bounded application-level admission above transport/protocol limits. Per-worker/group policy can limit active requests, connections or streams without creating an unbounded queue.

Overload is request/stream local where the protocol permits it:

- HTTP/1.1 uses a bounded service-unavailable response/connection behavior;
- HTTP/2 and HTTP/3 reject/refuse the affected stream/request rather than crashing the worker;
- host runtimes use their supported response path.

Rejected requests/connections are classified in metrics. Capacity returning after one rejected request must immediately be usable by later work.

Treat admission limits as safety boundaries. Raising them increases the amount of simultaneously retained application/protocol state and should be tested together with memory, connection and stream ceilings.

## Metrics, diagnostics and control status

Runwire maintains fixed-cardinality runtime metrics rather than per-request/per-connection history. The versioned snapshot includes bounded counters/gauges for requests, failures, connections, bytes, memory, streams, backpressure, overload, event-loop health, worker age/busy time and protocol-specific state.

Application errors are classified separately, including protocol/transport failures, handler exceptions, resetter failures, deadline expiry, client cancellation, overload rejection and warmup failure.

The control server serializes bounded status and supports the established control actions (`status`, `reload`, `recycle`, `stop`) subject to its version and response-size limits. Normal status must never dump unbounded request/connection collections or sensitive body/header/argv/environment data.

Operational state distinguishes:

```text
live       runtime/process control path is functioning
ready      capacity can accept new work
healthy    no configured health failure is active
draining   new work is intentionally refused while admitted work finishes
```

Worker activity state additionally distinguishes starting, ready/idle, busy, draining, unhealthy, stopping and exited. PID existence alone is not a health signal.

`DiagnosticsPolicy` controls bounded diagnostic thresholds such as busy-worker duration, callback overrun and worker report interval. Debug diagnostics should remain bounded and are not a substitute for an external tracing/APM product.

## GC policy

Request execution policy includes controlled GC behavior. Do not call `gc_collect_cycles()` unconditionally after every request. Use request-count/memory-growth thresholds and minimum intervals appropriate for the application, then verify the result with long-running soak measurements.

## CPU/cgroup-aware worker sizing

Explicit worker counts always win. A native server may opt into automatic sizing with `workers: 0`; Runwire then uses effective execution capacity rather than blindly using the physical-host CPU count.

On supported Linux/container environments, resource detection considers cgroup CPU quota/cpuset and memory limits. Unsupported or unreadable platforms fall back conservatively rather than failing normal startup.

Expose/review the resolved worker count and detected limits in diagnostics. Automatic sizing is a starting point, not a substitute for measuring blocking application work, CPU saturation and memory per worker.

## Privilege drop

Native Unix prefork deployments can opt into worker UID/GID reduction through `PrivilegeDropPolicy` after privileged master setup/listener binding.

Rules:

- disabled unless explicitly configured;
- preflight validation happens before serving;
- failure is explicit when POSIX identity changes are unavailable or invalid;
- application worker resources are created after the child identity/ownership boundary as appropriate;
- do not use privilege-drop configuration as a replacement for filesystem/network permission design.

Test the real service account and listener ownership in the target deployment before enabling production traffic.

## SO_REUSEPORT

`SO_REUSEPORT` is an advanced capability and is **off by default**. Standard native prefork uses master-owned/inherited listener semantics.

When explicitly enabled, Runwire probes runtime support first. Unsupported platforms/configurations fail clearly instead of silently ignoring the request. TCP and UDP/QUIC ownership must be evaluated separately.

Native HTTP/3 with more than one independently bound QUIC worker requires explicit reuse-port configuration because each worker owns its QUIC socket. Do not enable it merely because the host kernel exposes the option; verify load-distribution and operational behavior for the deployment.

## Worker roles, periodic tasks and service workers

Worker groups can identify roles such as HTTP, TASK, SERVICE and CUSTOM. Roles improve lifecycle defaults/status clarity without creating a second task framework.

`WorkerContext::every()` registers bounded, named recurring work on the existing worker timer infrastructure. Names are unique per worker context and return a cancellation handle.

When drain begins:

- future periodic executions stop being scheduled;
- optional new background work must not be claimed;
- already-running work may finish within the worker drain/shutdown deadline;
- a stuck callback remains bounded by supervisor shutdown enforcement.

SERVICE groups default toward stable/non-application-reload behavior where configured; all groups still participate in full supervisor shutdown.

## Development watcher

The optional development watcher triggers the existing graceful rolling-reload path. It is **disabled by default** and should remain disabled in production.

The watcher uses bounded file scanning/polling with debounce behavior and failure isolation. A watcher error is observable but must not kill a healthy runtime. Production deployments should use their normal deployment/reload control plane instead of filesystem polling.

## Default protocol ceilings

Defaults are intentionally conservative. Increase them only after measuring memory, file-descriptor and latency behavior in the real deployment.

| Area | HTTP/1.1 | HTTP/2 | HTTP/3 |
| --- | ---: | ---: | ---: |
| Request body | 16 MiB | 16 MiB | 16 MiB |
| Header / field-section bytes | 64 KiB | 64 KiB | 64 KiB |
| Header fields | 100 | 100 | 128 |
| Concurrent streams | n/a | 100 | 100 |
| Lifetime request streams | keep-alive: 1,000 | 10,000 | 10,000 |
| Pending response per stream | bounded by response writer | 1 MiB | 1 MiB |
| Pending response per connection | bounded connection buffer | 8 MiB | 8 MiB |
| Compression table | n/a | HPACK 4 KiB | QPACK 64 KiB maximum |
| Blocked compression streams | n/a | n/a | 32 |

Additional bounded defaults include HTTP/2 control-frame work, continuation count, response wire queue, HTTP/3 peer unidirectional-stream churn, QPACK blocked bytes, QPACK encoder queue bytes, reads/writes per pump and inbound bytes per pump.

The constructor validation in `Http1Limits`, `Http2Limits` and `Http3Limits` is authoritative. Invalid watermark relationships or non-positive hard ceilings are rejected before serving traffic.

## Backpressure tuning

Treat backpressure ceilings as protection boundaries, not throughput targets.

- Keep low/high watermarks separated enough to avoid pause/resume thrashing.
- Do not raise per-stream response buffering without considering aggregate connection buffering and worker concurrency.
- Raising HTTP/2 or HTTP/3 concurrent-stream counts multiplies per-connection state and should be paired with connection-level limits.
- Larger HPACK/QPACK dynamic tables may improve compression at the cost of retained state and more expensive churn.
- Large request-body allowances should not imply equally large in-memory pending-body buffers; Runwire streams bodies and maintains independent pending-buffer ceilings.
- Increase HTTP/3 per-pump read/write budgets only when event-loop fairness remains acceptable under multiplexed load.

## Event loop and worker sizing

`SelectLoop` is the portable native baseline. `ext-event` is optional for deployments that benefit from a different event backend.

Worker count should be chosen from measured CPU saturation, blocking application work and memory per worker. More workers do not compensate for unbounded application blocking. For persistent runtimes, configure recycle/admission policy from observed request volume, retained memory and expected worker lifetime; keep per-request cleanup enabled regardless of the host runtime.

## Reverse proxies and load balancers

When a proxy terminates HTTP/TLS/QUIC, the proxy owns that wire protocol. Runwire should be configured for the actual downstream mode rather than claiming native wire ownership it does not have.

For native HTTP/3, the load-balancing path must support UDP/QUIC to the Runwire listener. A TCP-only forwarding path cannot carry native HTTP/3. HTTP/3 capability advertised by an upstream proxy does not mean the downstream Runwire process owns HTTP/3.

## Security and operations checklist

Before production rollout, verify that:

- no untrusted value is passed through an implicit shell path;
- process children are bounded, supervised and reaped;
- request, header, stream, frame, body, queue and response limits are explicitly reviewed;
- TLS key material has appropriate filesystem permissions;
- HTTP/3 0-RTT remains disabled unless a future replay-safety policy explicitly enables it;
- persistent application state is reset after every request, including failed handlers/cancellation paths;
- request deadline/cancellation behavior is exercised with the application;
- boot/warmup failure cannot advertise readiness;
- reload replacement readiness and rollback behavior are exercised before deployment;
- worker recycle thresholds and jitter are explicitly reviewed rather than assumed;
- admission/rejection thresholds are measured under overload;
- metrics/control output stays bounded and does not expose sensitive values;
- application DB/cache/broker connections are created in the correct post-fork worker lifetime;
- privilege-drop UID/GID and filesystem permissions are verified when enabled;
- `SO_REUSEPORT` remains off unless explicitly required and validated;
- the development watcher is disabled in production;
- shutdown/reload/recycle deadlines are exercised before production traffic is enabled.

## Validation before rollout

Use the same supported PHP versions and optional capabilities as production. At minimum run the full PHPForge QA matrix, the QUIC-present HTTP/3 lane when enabling native HTTP/3, cross-driver lifecycle acceptance, portable soak/fault coverage and the dedicated benchmark workflow.

Benchmark results are evidence for regression tracking, not universal capacity claims. Cross-runtime comparisons must use equivalent real runtimes, workload, protocol, PHP version, worker count, concurrency and hardware and must record throughput, latency percentiles, errors, CPU and RSS. See `docs/benchmarks.md`.
