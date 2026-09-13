# Runwire 1.0 — Final Runtime Hardening & Foundation 3 Launch Plan

## Status

Target release: **Runwire 1.0**

Primary launch consumer: **Foundation 3**  
Primary HTTP integration: **Webrick**  
Primary messaging integration: **Omnibus**  
PHP baseline: **^8.4**

Package identity:

```text
Composer:  infocyph/runwire
Namespace: Infocyph\Runwire
```

Tagline:

> A high-performance process and network runtime for PHP.

Runwire is the low-level Infocyph process, supervisor, event-loop, network, HTTP and host-runtime substrate. It must remain framework agnostic and capability driven.

Priority:

> correctness → process isolation/ownership → persistent-runtime safety → bounded resource use → graceful lifecycle → observability → performance → scalability → ergonomics

Architectural references for this final 1.0 hardening pass include Laravel Octane, Workerman, Symfony Runtime and Hyperf. Runwire may adopt useful runtime concepts from them, but must not depend on, clone, or become an application framework like any of them.

---

# 1. Current baseline

The previous release-check candidate was:

```text
7ff7eba694e4b15b1620aa30456b23ab0804f633
```

At that head the existing Runwire implementation had already passed:

- native HTTP/1.1, HTTP/2 and HTTP/3 implementation and semantic parity;
- HPACK/QPACK protocol acceptance and bounded abuse/fault coverage;
- QUIC v1 + TLS 1.3 + `h3` integration;
- aioquic and source-built ngtcp2/nghttp3 independent interoperability;
- graceful HTTP/3 GOAWAY/drain/reload and explicit 0-RTT-disabled policy;
- FPM, FrankenPHP, Swoole/OpenSwoole and RoadRunner host-driver execution;
- persistent host-driver request-isolation/recycle acceptance;
- HTTP/1/2/3 and host-runtime soak/fault acceptance;
- PHPBench and native HTTP/3 benchmark evidence;
- public deployment/tuning/benchmark documentation;
- PHP 8.4/8.5 PHPForge QA, analyzers and clean-install gates.

Those completed items are **not part of the active tracker anymore**. Existing tests and behavior remain regression requirements.

Because the 1.0 scope is now expanded by the 60 points below, `7ff7eba6` is a **pre-hardening baseline**, not the final release candidate. A new exact-head release certification is required after this plan is complete.

---

# 2. Hard scope boundaries

Runwire owns:

- process execution and prefork supervision;
- worker groups, generations, readiness, reload, recycle and shutdown;
- event-loop/timer/deferred/watcher mechanics;
- TCP, UDP, Unix sockets and TLS transport;
- native HTTP/1.1, HTTP/2 and HTTP/3 runtime mechanics;
- bounded protocol/network resource policy;
- host-runtime adaptation for FPM, FrankenPHP, Swoole/OpenSwoole and RoadRunner;
- generic application/runtime lifecycle contracts;
- request/runtime context, cancellation, deadlines and reset lifecycle;
- runtime metrics/diagnostics/control contracts;
- capability detection and capability-first behavior.

Runwire does **not** own:

- DI/MVC/ORM/application containers;
- application routing/controllers/middleware;
- auth/session/business validation;
- database/cache/queue frameworks;
- Hyperf-style AOP/annotation/RPC framework features;
- Octane cache/table application features;
- Workerman-style global static application programming model;
- a full coroutine/Fiber application framework;
- systemd/Docker/Kubernetes replacement;
- arbitrary remote shell or fake PHP sandbox;
- custom QUIC cryptography, congestion control or loss recovery.

Foundation/Webrick/Omnibus remain untouched until the new Runwire 1.0 release gates close.

---

# 3. Dependency, structure and design rules

- Runwire must not require Foundation, Webrick, Omnibus, ReqShield, Pathwise, InterMix, DBLayer or CacheLayer.
- PHPForge remains development-only.
- QUIC remains optional at package-install time; native HTTP/3 fails fast when its runtime capability is unavailable.
- Generic runtime behavior must live above driver-specific options wherever technically possible.
- Consumers should use capabilities and contexts rather than runtime-name switches.
- Persistent/request-local state must never rely on unbounded process-global mutable storage.
- All deadlines and lifecycle durations use monotonic time.
- Runtime/metrics/diagnostics APIs must have bounded cardinality and bounded response sizes.
- New hot-path instrumentation must be cheap when disabled.
- New optional OS features must fail capability checks cleanly and must not reduce distro portability.
- **All enums must live in dedicated domain-local `Enum/` directories and namespaces.** Do not leave enum declarations mixed beside service/state-machine/value-object classes simply because they were originally top-level.
- Prefer domain-local enum ownership such as `Runtime/Enum`, `Supervisor/Enum`, `Process/Enum`, `Network/Enum`, `Http/Http2/Enum` and `Http/Http3/Enum`; use a root enum namespace only for a truly cross-domain enum with no clearer owner.
- Because Runwire 1.0 is unreleased, enum namespace/directory normalization may make clean namespace changes now; do not add compatibility aliases solely to preserve unreleased paths.
- No “fastest PHP framework/runtime” or “top of the whole PHP ecosystem” claim may be made from architecture or one CI result alone. Performance positioning requires reproducible comparative evidence with workload, protocol, worker count, concurrency, hardware, PHP/runtime versions, errors, CPU/RSS and latency percentiles recorded.
- Runwire runtime-layer results must not be presented as Foundation/Webrick full-framework results; those require a later integrated benchmark after the consumer stack exists.

---

# 4. Implementation tracker

Last updated: **2026-09-13**

Tracker semantics:

- `⬜` = not yet implemented/accepted for the expanded 1.0 scope.
- `🔄` = current batch.
- `✅` = implemented and its batch QA is green.
- **Required** blocks Runwire 1.0 release.
- **Recommended** also belongs to Runwire 1.0 and must be completed; the label describes API importance, not release optionality.
- **Optional/advanced** means the capability is optional or disabled by default at runtime, but its implementation/tests still belong to Runwire 1.0 because this plan includes all 60 points.

## Batch tracker

| Batch | Points | Scope | Status |
| --- | ---: | --- | --- |
| 0 | — | Enum directory/namespace normalization | ✅ Green (`1868a82f`) |
| A | 1–4 | Generic worker recycling and accounting | ✅ Green (`a95c743c`) |
| B | 5–10 | Runtime/request context, cancellation and deadlines | ✅ Green (`921b3589`) |
| C | 11–15 | Application lifecycle, resetters, boot/warmup/drain | ✅ Green (`04c97cc7`) |
| D | 16–18, 45–50 | Rolling reload, health, generation and restart semantics | ✅ Green (`47cfb924`) |
| E | 19–25, 39–44, 51–53 | Metrics, diagnostics, errors, timing and observability | 🔄 Active |
| F | 26–30, 33–35, 57 | Admission, resource/environment policy and socket capabilities | ⬜ Pending |
| G | 31–32, 36, 55 | Timers, task/service workers, watcher and drain-aware background work | ⬜ Pending |
| H | 37–38, 54, 56 | Bootstrap/runtime contracts, warmup and capability-first APIs | ⬜ Pending |
| I | 58–60 | Cross-driver parity, soak/fault and final acceptance expansion | ⬜ Pending |
| J | — | Benchmarks/docs refresh + comparative performance evidence + exact-head PHP 8.4/8.5 release QA | ⬜ Pending |
| Release | — | Tag/publish Runwire 1.0 only after explicit approval | ⬜ Blocked |

Batch 0 certification evidence:

```text
Exact head: 1868a82fee0e8a35b7e94131bc45c5f729983f1b
Benchmarks #16: green
Security & Standards #203: green
PHP 8.4/8.5 QA/analyzers/clean install: green
QUIC/aioquic/native H3 soak/ngtcp2+nghttp3 lanes: green
```

Batch A certification evidence:

```text
Exact head: a95c743ca8efa0b1b024526b817d79db0d466da4
Benchmarks #24: green
Security & Standards #211: green
PHP 8.4/8.5 prefer-stable/prefer-lowest QA: green
PHP 8.4/8.5 PHPStan/Psalm analyzers and clean install: green
QUIC/aioquic/native H3 soak/ngtcp2+nghttp3 lanes: green
```

Batch B certification evidence:

```text
Exact head: 921b3589d1af04998b54f8bcf8e678e7b2a3912f
Benchmarks #27: green
Security & Standards #214: green
PHP 8.4/8.5 prefer-stable/prefer-lowest QA: green
PHP 8.4/8.5 PHPStan/Psalm analyzers and clean install: green
QUIC/aioquic/native H3 soak/ngtcp2+nghttp3 lanes: green
```

Batch C certification evidence:

```text
Exact head: 04c97cc716679e19e0d4c3e591cafc2844a59395
Benchmarks #32: green
Security & Standards #219: green
PHP 8.4/8.5 prefer-stable/prefer-lowest QA: green
PHP 8.4/8.5 PHPStan/Psalm analyzers and clean install: green
QUIC/aioquic/native H3 soak/ngtcp2+nghttp3 lanes: green
QA warning-clean: green
```

Batch D certification evidence:

```text
Exact head: 47cfb92486c86c1bd7a08283c9c5e5cbd360efa9
Benchmarks #39: green
Security & Standards #226: green
PHP 8.4/8.5 prefer-stable/prefer-lowest QA: green
PHP 8.4/8.5 PHPStan/Psalm analyzers and clean install: green
QUIC/aioquic/native H3 soak/ngtcp2+nghttp3 lanes: green
```

Current implementation handoff:

```text
Batch E → metrics, diagnostics, errors, timing and observability
```

Batch E must be independently green before Batch F implementation begins.

---

## Batch 0 — Enum directory and namespace normalization

Before beginning Point 1, normalize enum placement across the existing codebase.

Required structure rule:

```text
src/Runtime/Enum/*
src/Supervisor/Enum/*
src/Process/Enum/*
src/Network/Enum/*
src/Protocol/Enum/*
src/Http/Enum/*
src/Http/Http1/Enum/*
src/Http/Http2/Enum/*
src/Http/Http3/Enum/*
src/Http/Http3/Qpack/Enum/*
```

Use the nearest owning domain rather than forcing every enum into one global directory. Add another domain-local `Enum/` directory whenever a future domain owns multiple or domain-specific enums.

The migration must include all currently applicable enums, including existing runtime/transport/process/supervisor/protocol and HTTP/1/2/3/QPACK enums, with exact final ownership decided by semantic domain.

Requirements:

- filesystem path and namespace must agree;
- imports/usages/tests/benchmarks/docs are updated atomically;
- no duplicate enum definitions or compatibility aliases for unreleased namespaces;
- no enum remains mixed at a domain root when a clear `Enum/` owner directory exists;
- public API documentation is updated where enum FQCNs are referenced;
- a regression test must fail if a future enum is added outside a domain-local `Enum/` directory/namespace;
- full PHPForge QA/analyzers/clean-install gates must be green before Batch 0 is marked complete.

---

# 5. Runwire 1.0 hardening program — 60 points

## Batch A — Generic worker recycling and accounting

### 1. Generic `WorkerRecyclePolicy` — Required

Introduce one runtime-neutral worker recycle policy instead of leaving equivalent behavior inside individual host options.

Recommended policy surface:

```php
new WorkerRecyclePolicy(
    maxRequests: 10_000,
    maxLifetimeSeconds: 3_600,
    maxMemoryBytes: 268_435_456,
    jitterRequests: 500,
    jitterSeconds: 120,
    gracefulTimeoutSeconds: 10.0,
);
```

Requirements:

- thresholds are soft recycle triggers, not immediate kill commands;
- active work drains before exit within configured bounds;
- persistent native/host workers share the same semantic contract where possible;
- non-persistent runtimes ignore unsupported recycle dimensions explicitly rather than pretending they applied them.

### 2. Recycle jitter / restart staggering — Required

Prevent synchronized worker retirement when workers start together and share identical thresholds.

Requirements:

- bounded jitter for request/lifetime recycling;
- deterministic/testable injection or seed strategy;
- jitter never exceeds configured safety bounds;
- status/diagnostics expose the effective recycle threshold/deadline where useful.

### 3. Generic request-count accounting — Required

Track request totals per persistent worker independently of the concrete runtime driver.

Use the counter for:

- request-limit recycling;
- status/diagnostics;
- failure-rate metrics;
- soak and benchmark evidence.

Accounting must not double-count retries, protocol fragments or host callbacks representing one logical request.

### 4. Memory-aware worker recycling — Required

Allow worker recycling after configurable memory thresholds.

Requirements:

- current and peak memory measurements;
- soft threshold triggers graceful recycle;
- no process-global hard kill solely because one request crosses a soft threshold;
- distinguish configured memory ceiling from observed peak;
- threshold checks remain cheap enough for persistent request paths.

---

## Batch B — Runtime/request context, cancellation and deadlines

### 5. Immutable `RuntimeContext` — Required

Expose runtime facts to applications/framework adapters without requiring driver-name branching.

Recommended information:

```text
driver
mode
worker slot
generation
pid
persistent
concurrent
owns listener
owns event loop
owns worker pool
runtime capabilities
```

The context is immutable for the lifetime of one application/worker instance.

### 6. First-class `RequestContext` — Required

Create explicit request/stream-local execution state.

Recommended information:

```text
request id
start monotonic time
deadline
cancellation token
request-local attributes
runtime context reference
```

HTTP/2 and HTTP/3 concurrent streams must never share mutable request context.

### 7. Request-scoped storage — Required

Provide bounded request-local key/value storage through `RequestContext`.

Rules:

- never expose a magic process-global `Context::get()` store;
- clear all request-local values at request completion;
- permit framework integrations to hold trace/auth/local-cache metadata safely;
- document that values must not be used as unbounded arbitrary storage.

### 8. Cancellation token — Required

Add a common cooperative cancellation contract.

Cancellation sources include:

- client disconnect;
- HTTP/2 `RST_STREAM`;
- HTTP/3 `RESET_STREAM` / `STOP_SENDING`;
- request deadline expiry;
- worker drain/shutdown where the request can no longer continue;
- host-runtime cancellation when exposed by the host.

Cancellation callbacks must be idempotent and exception-isolated.

### 9. Request deadline abstraction — Required

Represent execution deadlines using monotonic time and request-local state.

Requirements:

- deadline lookup/check is cheap;
- expiry requests cancellation;
- no process-global alarm for multiplexed HTTP/2/3 work;
- host drivers map native timeout/cancellation semantics where possible;
- `null`/unlimited behavior is explicit and bounded by outer worker/server shutdown policy.

### 10. Configurable request execution limit — Required

Provide a generic maximum request execution policy implemented through request deadlines/cancellation.

Do not use `pcntl_alarm()` as the universal implementation because one process may serve concurrent H2/H3 streams or host-runtime requests.

---

## Batch C — Application lifecycle, resetters, boot/warmup/drain

### 11. Formal application lifecycle — Required

Evolve the existing application contract into explicit lifecycle phases:

```text
boot()       once per worker/application instance
warmup()     optional, before readiness
handle()     every request
reset()      after every request, including exception paths
drain()      stop initiating new application work
shutdown()   exactly once
```

Existing request cleanup guarantees remain mandatory.

### 12. Request resetter registry — Required

Support composable request resetters instead of one monolithic cleanup callback.

Suggested contract:

```php
interface RequestResetterInterface
{
    public function reset(RequestContext $context): void;
}
```

Typical integrations may reset container scopes, DB transactions, logger context, locale, tracing state or framework-local caches.

### 13. Resetters run despite failures — Required

Every registered resetter must get a chance to execute even when:

- the request handler throws;
- an earlier resetter throws;
- response completion fails.

Aggregate/report cleanup failures without leaking stale request state into the next request.

### 14. Worker boot/warmup hook — Required

Provide an explicit worker-local initialization stage after fork/worker creation.

Use cases:

- application/container boot;
- reusable clients created with correct process ownership;
- routing/config precomputation;
- controlled cache warmup.

No worker may become ready before required boot/warmup succeeds.

### 15. Worker drain hook — Required

Notify the application when a worker begins draining.

Applications can then stop:

- claiming new background jobs;
- opening new long-lived streams;
- scheduling new periodic work;
- initiating optional work that would extend drain unnecessarily.

Existing requests continue within the configured drain deadline.

---

## Batch D — Rolling reload, health, generation and restart semantics

### 16. Configurable rolling reload policy — Required

Introduce explicit rolling replacement policy, for example:

```php
new ReloadPolicy(
    maxUnavailable: 0,
    maxSurge: 1,
    replacementReadyTimeoutSeconds: 10.0,
    drainTimeoutSeconds: 30.0,
);
```

Preferred order:

```text
spawn replacement
→ wait until replacement ready
→ drain old worker
→ old worker exits
→ continue to next slot
```

### 17. Non-reloadable worker groups — Recommended

Allow groups such as infrastructure/control/service workers to survive application-code reloads when configured:

```text
reloadable = false
```

They still participate in full supervisor shutdown and explicit recycle when appropriate.

### 18. Worker health state — Required

Expand worker lifecycle/health representation to distinguish at least:

```text
starting
ready
idle
busy
draining
unhealthy
stopping
exited
```

Do not infer health only from PID existence.

### 45. Startup readiness barrier — Required

A generation becomes ready only after its required worker groups satisfy readiness policy.

Readiness must account for boot/warmup failure and timeout.

### 46. Minimum-ready workers during reload — Required

Never drain old capacity below configured availability while replacement workers are not ready.

`maxUnavailable` / minimum-ready rules must be enforced by the supervisor, not left to application convention.

### 47. Reload failure rollback — Required

If replacement workers repeatedly fail startup/readiness:

- stop destructive rollout;
- keep healthy old-generation capacity alive;
- expose the failed rollout in status/events;
- apply bounded retry/backoff policy;
- require explicit follow-up when retry budget is exhausted.

### 48. Generation-aware traffic ownership — Required

Expose generation ownership consistently for:

- current workers;
- replacement workers;
- draining workers;
- status/diagnostics/events.

This must make rolling reload state observable and testable.

### 49. Shutdown reason propagation — Recommended

Pass a stable reason to drain/shutdown lifecycle callbacks, such as:

```text
deployment_reload
recycle_request_limit
recycle_memory_limit
recycle_lifetime
manual_recycle
supervisor_stop
fatal_runtime_error
```

### 50. Exit/restart reason classification — Required

Track expected and unexpected worker exits separately.

Examples:

```text
normal_shutdown
planned_reload
planned_recycle
startup_failure
readiness_timeout
application_fatal
signal_exit
crash
restart_budget_exhausted
```

Expose cumulative restart/exit reasons in diagnostics without unbounded history.

---

## Batch E — Metrics, diagnostics, errors, timing and observability

### 19. Per-worker runtime telemetry — Required

Expose bounded counters/gauges including:

```text
requests_total
requests_active
requests_failed
connections_active
connections_accepted_total
bytes_read_total
bytes_written_total
memory_bytes
memory_peak_bytes
timers_active
worker_busy
worker_age_seconds
backpressure_events
rejected_connections
rejected_requests
```

### 20. Protocol-specific telemetry — Required

Expose bounded protocol measurements where applicable:

```text
http1_connections_active
http2_connections_active
http2_streams_active
http2_streams_total
http2_resets_total
http3_connections_active
http3_streams_active
http3_streams_total
http3_resets_total
hpack_table_bytes
qpack_table_bytes
qpack_blocked_streams
```

Never expose attacker-controlled header values or unbounded per-stream labels.

### 21. Event-loop diagnostics — Required

Track useful loop health data such as:

- loop/tick duration;
- observable loop lag;
- timer count;
- deferred callback backlog where meaningful;
- callback execution overrun counters.

Instrumentation must avoid creating a new latency problem.

### 22. Busy-worker detection — Recommended

Identify workers that remain busy/unresponsive beyond configured diagnostic thresholds.

Detection should use active-request duration and loop responsiveness rather than only CPU assumptions.

Automatic termination is not implied; recycle/kill policy must remain explicit.

### 23. Versioned metrics snapshot API — Required

Provide a stable runtime-neutral metrics snapshot contract, for example:

```php
interface MetricsProviderInterface
{
    public function snapshot(): RuntimeMetricsSnapshot;
}
```

Do not add Prometheus/OpenTelemetry as core dependencies. Exporters belong to adapters/integration packages.

### 24. Expand `ControlServer` status — Required

Extend the existing bounded control protocol to return the new worker/runtime metrics and lifecycle state.

Requirements:

- protocol remains versioned;
- response-size ceilings remain enforced;
- status cannot dump unbounded connection/request collections;
- old control actions (`status`, `reload`, `recycle`, `stop`) remain stable unless deliberately versioned.

### 25. Health/readiness/liveness distinctions — Required

Define independent semantics:

```text
live      process/runtime is functioning
ready     capable of accepting new work
healthy   no configured health failure is active
draining  intentionally refusing new work while finishing existing work
```

Frameworks/orchestrators may expose these as endpoints, but Runwire owns the underlying truth.

### 39. Long-running safety diagnostics — Recommended

In debug/diagnostic mode, record suspicious per-request growth signals such as:

- memory delta;
- active timers before/after request;
- pending response/body state;
- cleanup/resetter failures;
- retained runtime-owned request state.

Do not claim perfect arbitrary PHP static-leak detection.

### 40. GC policy — Recommended

Allow controlled garbage-collection policy, for example:

```text
gcEveryRequests
gcMemoryGrowthBytes
```

Avoid unconditional `gc_collect_cycles()` after every request.

### 41. Connection/stream lifetime reporting — Recommended

Expose aggregate/high-watermark diagnostics such as:

```text
oldest_connection_age_seconds
longest_active_request_seconds
peak_connections
peak_streams
```

Do not expose an unbounded live connection dump through the normal status API.

### 42. Application error accounting — Required

Classify request/runtime failures separately:

```text
protocol_error
transport_error
handler_exception
resetter_failure
deadline_exceeded
client_cancelled
overload_rejection
warmup_failure
```

Metrics/status must not collapse all of them into one generic failure counter.

### 43. Lifecycle event expansion — Required

Extend lifecycle events with stable events such as:

```text
WORKER_DRAIN_STARTED
WORKER_DRAIN_COMPLETED
WORKER_UNHEALTHY
WORKER_RECYCLE_COMPLETED
REQUEST_DEADLINE_EXCEEDED
```

High-volume per-request events should remain opt-in or carefully bounded.

### 44. Lifecycle-listener fault isolation — Required

Preserve and strengthen the existing listener-failure isolation model.

Requirements:

- listener exceptions never destabilize supervisor state where safe;
- failures are counted/classified;
- one bad listener does not prevent other listeners from receiving the event;
- failure behavior is explicitly tested for new lifecycle events.

### 51. Structured runtime diagnostics snapshot — Required

Define stable DTO/value objects for diagnostics rather than passing arbitrary internal arrays between subsystems.

The control server serializes the stable representation; tests/exporters consume it.

### 52. Request ID generation / propagation hook — Recommended

Provide a small request-ID policy/hook:

- validate/reuse acceptable inbound IDs when configured;
- otherwise generate a bounded ID;
- store it in `RequestContext`;
- make it available to lifecycle/error diagnostics;
- do not force a specific tracing vendor or header convention.

### 53. Monotonic timing everywhere — Required

Audit all lifecycle durations and deadlines so these use monotonic clocks:

- request elapsed time;
- request deadline;
- worker lifetime;
- readiness timeout;
- shutdown/drain timeout;
- reload timing;
- recycle lifetime/jitter;
- retry/backoff timing.

Wall-clock values may still be included separately for human-readable timestamps.

---

## Batch F — Admission, resource/environment policy and socket capabilities

### 26. Overload/admission policy — Required

Add runtime/worker-level admission limits above existing transport/protocol limits.

Candidate limits:

```text
maxActiveRequests
maxQueuedRequests
maxConcurrentConnections
maxStreamsPerWorker
overloadStrategy
```

All queues remain locally bounded.

### 27. Graceful overload rejection — Required

Use protocol-aware rejection instead of unbounded queuing:

- HTTP/1.1: bounded 503/connection close where safe;
- HTTP/2: stream-level refusal/reset semantics where appropriate;
- HTTP/3: request-level rejection where appropriate;
- host runtimes: supported host response path.

Overload of one request/stream must not unnecessarily crash the worker.

### 28. CPU-aware automatic worker sizing — Recommended

Provide a generic automatic worker-count strategy based on available execution capacity.

Requirements:

- explicit worker count always wins;
- avoid naïvely using physical host CPU count inside constrained containers;
- use the cgroup-aware detection from Point 29 when available;
- expose the resolved worker count in diagnostics.

### 29. Container/cgroup-aware resource detection — Recommended

Detect effective CPU and memory constraints in containers where supported.

Use these signals for:

- automatic worker sizing;
- diagnostics;
- safe default recommendations.

Unsupported platforms must fall back predictably without failing normal runtime startup.

### 30. Privilege-drop support — Recommended

For native Unix prefork mode, optionally allow workers to drop to configured UID/GID after privileged master setup.

Requirements:

- explicit opt-in only;
- no silent privilege changes;
- validation before serving;
- clear failure when requested but unsupported;
- test ownership/order around listener binding and child bootstrap.

### 33. Worker-group resource policy — Recommended

Allow different worker groups to carry different:

- recycle policy;
- request/admission limits;
- memory/lifetime policy;
- readiness/warmup policy;
- reloadability;
- shutdown/drain policy.

Avoid one global configuration that cannot model heterogeneous worker roles.

### 34. `SO_REUSEPORT` support — Optional/advanced

Add explicit listener reuse-port capability with **default off**.

Requirements:

- capability probe before use;
- Linux/OS behavior documented;
- native inherited-listener prefork remains the standard default;
- TCP and UDP/QUIC ownership semantics tested independently;
- unsupported platforms fail configuration clearly when explicitly requested.

### 35. Native socket-option capability reporting — Recommended

Expose capability facts such as:

```text
supports_reuse_port
supports_unix_sockets
supports_fork
supports_signals
supports_quic
```

Prefer capability checks over platform/distro-name checks.

### 57. Preserve driver-specific options only for true driver differences — Required

Audit `RuntimeOptions` and individual driver option classes.

Move common behavior upward into generic policies:

- recycle thresholds;
- request execution deadlines;
- metrics/diagnostics configuration;
- reset/lifecycle policy;
- readiness policy;
- common admission controls.

Keep only genuine host-native knobs in `FpmOptions`, `FrankenPhpOptions`, `SwooleOptions` and `RoadRunnerOptions`.

---

## Batch G — Timers, task/service workers, watcher and background-work lifecycle

### 31. Named periodic worker tasks — Recommended

Provide a small worker-context API for named recurring tasks using Runwire's existing timer infrastructure.

Example:

```php
$context->every('metrics-flush', 10.0, $callback);
```

Requirements:

- unique/bounded task naming per worker context;
- cancellation handle;
- no task survives worker shutdown;
- drain behavior follows Point 55.

### 32. Separate task/background worker-group roles — Recommended

Do not build a second task engine. Add worker-role metadata/policy such as:

```text
HTTP
TASK
SERVICE
CUSTOM
```

Use roles for lifecycle defaults, diagnostics and operator clarity.

### 36. Development file watcher — Optional tooling

Provide an optional development watcher that triggers the existing graceful reload path.

Requirements:

- no Node/chokidar runtime dependency in core;
- never enabled in production by default;
- filesystem-event implementation may be capability-specific with a bounded polling fallback;
- debounce reload storms;
- watcher failure must not kill a healthy runtime.

### 55. Drain-aware periodic/background work — Required

When drain begins:

- stop scheduling new periodic executions;
- stop claiming/starting new optional background work;
- allow currently running work a bounded completion window;
- cancel/terminate according to policy when the drain deadline expires.

---

## Batch H — Bootstrap/runtime contracts, warmup and capability-first APIs

### 37. Runtime bootstrap contract / application factory — Required

Introduce a clean application factory boundary, for example:

```php
interface RuntimeApplicationFactoryInterface
{
    public function create(RuntimeContext $context): RuntimeApplicationInterface;
}
```

Runtime selection/driver plumbing must not leak into normal application boot logic.

### 38. Explicit persistent-runtime declaration — Required

Expose whether one booted application instance serves multiple requests and whether execution may be concurrent.

Framework adapters must be able to validate unsafe assumptions before accepting traffic.

### 54. Warmup failure semantics — Required

If boot/warmup fails:

- worker never declares readiness;
- failure is classified and observable;
- supervisor applies bounded restart/backoff policy;
- rolling reload preserves healthy old-generation capacity according to Point 47.

### 56. Capability-first APIs instead of runtime-name checks — Required

Framework/application integrations should ask for capabilities such as:

```text
persistent
concurrent
owns_listener
owns_event_loop
supports_http2
supports_http3
supports_worker_recycle
supports_graceful_reload
```

Do not require application code to switch on `Swoole`, `RoadRunner`, etc. except for deliberately host-specific integrations.

---

## Batch I — Cross-driver contract, parity and soak/fault acceptance

### 58. Cross-driver contract tests — Required

For every generic lifecycle feature, prove behavioral compatibility across applicable persistent drivers.

Required acceptance areas:

```text
request reset
request context isolation
cancellation/deadline
max-request recycle
lifetime recycle
memory recycle
graceful drain
boot/warmup/readiness
rolling reload
telemetry accounting
shutdown reason
```

Where a host cannot support a feature, capability reporting and explicit unsupported semantics must be tested.

### 59. Native-vs-host semantic parity tests — Required

Extend protocol semantic-parity philosophy to runtime lifecycle behavior.

At minimum compare applicable behavior across:

```text
native persistent worker
FrankenPHP worker
RoadRunner
Swoole/OpenSwoole
```

FPM is included for request contract parity but is not treated as a persistent application worker.

### 60. New soak/fault coverage for all additions — Required

Run sustained/fault acceptance for:

- request reset loops;
- request-context creation/destruction;
- cancellation and deadline churn;
- request-count recycling;
- memory/lifetime recycling;
- rolling reload under active traffic;
- replacement startup/readiness failure and rollback;
- telemetry accumulation and snapshotting;
- repeated drain/restart cycles;
- timer/task cleanup;
- admission overload/recovery;
- `SO_REUSEPORT` separately when enabled;
- host-driver parity under repeated requests;
- HTTP/1/2/3 regression soak after lifecycle changes.

Acceptance remains:

- no unbounded RSS/FD/state growth attributable to Runwire;
- no zombie children;
- no request-context leakage;
- no stale timers/background work after worker retirement;
- no silent capacity cliff during rolling replacement;
- bounded metrics/diagnostics state;
- bounded overload degradation;
- no regression of existing H1/H2/H3/QUIC/QPACK acceptance.

---

# 6. Public API direction for the expanded 1.0

Likely stable public areas now include:

```text
Runwire\Runtime
Runwire\RuntimeOptions
Runwire\RuntimeCapabilities
Runwire\RuntimeContext
Runwire\RequestContext / request lifecycle contracts
Runwire\Server / listener definitions
Runwire\Loop contract
Runwire\Network bounded transport contracts
Runwire\Http\Enum\ProtocolVersion
Runwire\Supervisor
Runwire\Supervisor\WorkerGroup
Runwire\Supervisor\WorkerRecyclePolicy
Runwire\Supervisor\ReloadPolicy
Runwire\Supervisor\Enum\* lifecycle/status values
Runwire metrics/diagnostics snapshot contracts
Runwire\Process\Command / ProcessRunner / ProcessResult
```

Remain internal/narrow unless compelling:

- HTTP/1/2/3 parser state machines;
- HPACK/QPACK internal tables;
- php-quic engine-specific objects;
- supervisor bookkeeping collections;
- concrete host-runtime reflection/dynamic adapter helpers.

---

# 7. Security and safety invariants

Release blocking:

- no implicit shell path;
- no PHP `@` suppression;
- no unbounded request/context/metrics/diagnostic stores;
- no unbounded TCP/HTTP2/HTTP3/QPACK/HPACK buffers or stream counts;
- no process-global mutable request context;
- no cross-request application state retained by Runwire;
- pre-fork parent state must not create unsafe child-owned external connections;
- resetters/cleanup run under failure paths;
- deadlines are request-local and monotonic;
- cancellation callbacks are idempotent/isolated;
- rolling reload never knowingly destroys healthy capacity before replacements satisfy policy;
- lifecycle listeners cannot crash supervisor control flow where isolation is possible;
- all children are reaped;
- shutdown/reload/recycle remain bounded;
- status/metrics do not expose sensitive header/body/argv/env values;
- privilege drop is explicit and capability-validated;
- `SO_REUSEPORT` is explicit and capability-validated;
- QUIC engine crypto/loss/congestion logic remains delegated to maintained native implementation.

---

# 8. Per-batch quality gate

Every implementation batch must independently pass applicable checks before moving forward:

```text
focused Pest tests
full Pest suite
PHPStan
Psalm
Pint / PHP-CS-Fixer / PHPForge style gates
PHP 8.4 prefer-stable
PHP 8.4 prefer-lowest
PHP 8.5 prefer-stable
PHP 8.5 prefer-lowest
clean install
```

Where the batch affects native protocols/runtime ownership, also run applicable:

```text
QUIC extension-present PHP 8.4/8.5 lanes
QUIC extension-absent capability lane
HTTP/1/2/3 semantic parity
HTTP/3 aioquic interoperability
HTTP/3 source-built ngtcp2/nghttp3 interoperability
persistent host-driver acceptance
soak/fault acceptance
```

No batch should be hidden behind broad skips.

---

# 9. Benchmark and documentation refresh gate

The previous benchmark/docs work remains valid baseline evidence, but the new lifecycle/resource features can affect hot paths and operational guidance.

Before final certification:

- rerun PHPBench protocol/core and host-adapter benchmarks;
- rerun native HTTP/3 benchmark on supported QUIC runners;
- add benchmark coverage for request-context/reset/metrics overhead where meaningful;
- measure rolling reload capacity dip/recovery;
- measure worker recycle overhead under representative request rates;
- keep instrumentation-enabled and instrumentation-disabled cost distinguishable;
- run reproducible comparative runtime benchmarks against relevant PHP runtime/server baselines (Workerman, Swoole/OpenSwoole where directly comparable, FrankenPHP worker mode, RoadRunner and applicable Octane-backed modes) under the same hardware/PHP/workload/concurrency conditions;
- record RPS/throughput together with p50/p95/p99 latency, error rate, CPU and RSS; do not rank by RPS alone;
- separate plaintext/minimal-handler runtime overhead from JSON/body/streaming/concurrency workloads;
- benchmark native HTTP/1.1, HTTP/2 and HTTP/3 separately where peer implementations make an equivalent comparison possible;
- treat results as workload-specific evidence: use “top-tier” or stronger performance positioning only when reproduced comparative results support it;
- do not call Runwire the fastest PHP framework/runtime from architecture alone, and do not present Runwire-only measurements as Foundation/Webrick whole-framework results;
- document `WorkerRecyclePolicy`;
- document `RuntimeContext` and `RequestContext`;
- document cancellation/deadline behavior;
- document application boot/warmup/reset/drain/shutdown lifecycle;
- document rolling reload/readiness/rollback semantics;
- document metrics/diagnostics/control status;
- document admission/overload behavior;
- document CPU/cgroup auto-sizing;
- document privilege-drop support where available;
- document `SO_REUSEPORT` as advanced/default-off;
- document watcher as development-only;
- update host-driver capability matrix.

Do not publish universal throughput claims from one CI runner.

---

# 10. Final Runwire 1.0 completion gate

Runwire 1.0 is release-ready only when all of the following are true:

- [x] Batch 0 enum directory/namespace normalization is complete and green;
- [ ] Points **1–60** in this plan are implemented and accepted;
- [ ] every batch A–I is independently green;
- [ ] all new generic policies have cross-driver acceptance where applicable;
- [ ] persistent request state is isolated and reset under success, exception, cancellation and timeout paths;
- [ ] worker recycling works for request/lifetime/memory triggers without dropping active work outside configured bounds;
- [ ] rolling reload maintains configured minimum healthy capacity and rolls back failed replacement generations;
- [ ] readiness/health/liveness/draining states are explicit and observable;
- [ ] metrics/diagnostics/control snapshots are bounded and stable;
- [ ] overload/admission behavior remains bounded and protocol-aware;
- [ ] timers/background tasks obey drain/shutdown semantics;
- [ ] capability-first behavior replaces avoidable driver-name branching;
- [ ] native HTTP/1/2/3 and QUIC/QPACK regression suites remain green;
- [ ] aioquic interoperability remains green;
- [ ] source-built ngtcp2/nghttp3 interoperability remains green;
- [ ] FPM/FrankenPHP/Swoole/RoadRunner advertised behavior remains green;
- [ ] expanded soak/fault matrix shows no unbounded memory/FD/state growth;
- [ ] benchmark evidence is refreshed without benchmark-only runtime shortcuts;
- [ ] comparative runtime benchmark evidence is recorded with throughput, latency, error rate, CPU and RSS under reproducible equivalent workloads;
- [ ] public docs are refreshed for all new 1.0 lifecycle/resource/operations features;
- [ ] exact-head PHP 8.4/8.5 PHPForge matrix is fully green;
- [ ] exact-head dedicated benchmark workflow is fully green;
- [ ] exact-head native QUIC/HTTP3 integration lanes are fully green;
- [ ] no post-certification commit changes the release candidate SHA before tagging;
- [ ] Runwire 1.0 tag/release happens only after explicit approval;
- [ ] only after Runwire 1.0 release may Foundation/Webrick/Omnibus integration work resume.

---

# 11. Immediate implementation order

```text
0. Enum directory / namespace normalization                        ✅ 1868a82f
A. Points 1–4                  Worker recycling/accounting          ✅ a95c743c
B. Points 5–10                 Context/cancellation/deadlines       ✅ 921b3589
C. Points 11–15                Application lifecycle/reset/warmup/drain ✅ 04c97cc7
D. Points 16–18,45–50          Rolling reload/health/generation     ✅ 47cfb924
E. Points 19–25,39–44,51–53    Metrics/diagnostics/errors/timing    ← CURRENT
F. Points 26–30,33–35,57       Admission/resources/socket capabilities
G. Points 31–32,36,55          Timers/tasks/watcher/drain behavior
H. Points 37–38,54,56          Bootstrap/warmup/capability contracts
I. Points 58–60                Cross-driver parity + soak/fault
J. Benchmarks/docs refresh + comparative evidence + exact-head final release QA
K. Explicit approval → Runwire 1.0 tag/release
L. Then Webrick/Foundation/Omnibus integration
```

The next code change must stay inside **Runwire Batch E**. Do not modify Foundation, Webrick or Omnibus until this Runwire 1.0 program is complete and released.

---

# 12. Post-1.0 candidates

Not part of this 1.0 plan:

- WebTransport;
- HTTP Datagrams;
- MASQUE/connect-udp;
- advanced QUIC migration/path-management policy;
- application-visible 0-RTT after explicit replay-safety design;
- alternative QUIC engine adapters;
- advanced HTTP priority scheduling;
- WebSocket-over-HTTP/2/3 where justified;
- full Fiber scheduler / structured-concurrency framework;
- framework-level DI/AOP/ORM/RPC/cache/queue features.

These must not be pulled into 1.0 while the 60-point runtime-hardening program is active.