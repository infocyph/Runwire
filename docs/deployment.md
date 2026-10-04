# Runwire 2.0 Deployment and Operations

This guide covers production topology, capability requirements, lifecycle, TLS/HTTP3 deployment, admission/resource limits, control/reload behavior, host-runtime selection, and operational tuning.

For complete first-run examples, see [Getting Started](getting-started.md). For ownership rules, see [Architecture](architecture.md). For least privilege and production hardening, see [Runtime Security](security.md).

## 1. Production baseline

Runtime capability baseline:

| Capability | Production role | Required? |
| --- | --- | --- |
| 64-bit PHP `^8.4` | Core runtime requirement. | **Required** |
| `ext-pcntl` + `ext-posix` | Native prefork workers, reload/recycle, signals, control operations, privilege reduction. | Recommended for prefork; not required for portable native. |
| `ext-event` | Scalable native HTTP worker loop. | Strongly recommended for high-connection prefork HTTP; SelectLoop is the fallback. |
| `ext-openssl` | TLS and HTTP/2 ALPN. | Required only when TLS/HTTP2 is enabled. |
| `ext-quic` | Native QUIC and HTTP/3. | Required only when HTTP/3 is enabled. |
| `ext-swoole` / `ext-openswoole` | Swoole/OpenSwoole host integration. | Required only for that host; select `RuntimeDriver::SWOOLE` explicitly. |
| `ext-sockets` | Optional low-level socket features and transport tuning. | Optional. |
| OPcache | Persistent bytecode caching. | Recommended for production; required by release-certification performance evidence. |

## 2. Correct ownership model and host selection

Runwire-owned native server:

```php
Runtime::create($options)
    ->listen(Server::http('0.0.0.0:8080', $handler))
    ->run();
```

Auto-detected active host such as FPM, FrankenPHP, or RoadRunner:

```php
Runtime::create($options)->serve($handler);
```

or:

```php
Runtime::create($options)->serveApplication($factory);
```

Do not combine `listen()` with host-owned serving.

### Swoole/OpenSwoole

The presence of `ext-swoole` or `ext-openswoole` makes the Runwire Swoole driver available; extension installation alone does not mark an ordinary CLI process as an active Swoole host. Select it explicitly:

```php
use Infocyph\Runwire\Runtime;
use Infocyph\Runwire\Runtime\Enum\RuntimeDriver;
use Infocyph\Runwire\RuntimeOptions;
use Infocyph\Runwire\SwooleOptions;

$options = new RuntimeOptions(
    driver: RuntimeDriver::SWOOLE,
    swoole: new SwooleOptions(
        host: '0.0.0.0',
        port: 9501,
        workerCount: 0,
        http2: false,
    ),
);

Runtime::create($options)->serve($handler);
```

Either compatible Swoole-family extension can back `RuntimeDriver::SWOOLE`. Explicit selection fails startup if neither is available. Keep Swoole/OpenSwoole listener/reactor ownership in the host adapter; do not configure competing Runwire native listeners in the same runtime.

## 3. Native prefork versus portable native

| Feature | Prefork | Portable |
| --- | --- | --- |
| Runwire listener/event loop | yes | yes |
| Persistent application | yes | yes |
| Multiple worker processes | yes | no |
| Rolling reload | yes | no |
| Worker replacement/recycle | yes | no |
| Supervisor events/control | yes | no |
| Development watcher | yes | no |
| Privilege drop | capability-gated | no |
| HTTP/1.1 / HTTP/2 | yes | yes |
| HTTP/3 | with QUIC | with QUIC |
| Structured coroutines | yes | yes |

Portable mode keeps ordinary native serving available when PCNTL/POSIX are missing; it is not a substitute supervisor. Portable native uses SelectLoop. Prefork HTTP workers prefer ext-event when installed and otherwise use SelectLoop with a conservative 256-connection loop ceiling, so install ext-event for high-connection native HTTP deployments.

The portable contract is fail-closed:

| Configuration | Result |
| --- | --- |
| `workers: 0` or `workers: 1` | One process. |
| `workers > 1` | Startup error. |
| Worker recycle threshold enabled | Startup error. |
| Native control endpoint configured | Startup error. |
| Development watcher configured | Startup error. |
| Supervisor lifecycle listener configured | Startup error. |
| Worker privilege-drop configured | Startup error. |
| HTTP/3 configured without QUIC | Startup error. |

External supervision owns portable process restart/replacement.

## 4. Worker sizing

Explicit workers:

```php
$server = Server::http('0.0.0.0:8080', $handler)
    ->withWorkers(4);
```

Automatic prefork sizing:

```php
$server = new Server(
    name: 'web',
    address: '0.0.0.0:8080',
    handler: $handler,
    workers: 0,
);
```

Automatic sizing uses effective detected resources where available, including cgroup constraints.

Guidance:

- explicit counts override automatic sizing;
- automatic sizing is a deployment starting point, not a linear-throughput promise;
- benchmark representative 1/2/4-worker configurations against the same protocol, payload, concurrency and CPU quota before fixing a production count;
- short Runwire diagnostics showed useful but sublinear scaling (1→2 workers about 1.43×, 1→4 about 2.58×), so do not assume workers scale linearly;
- one multiplexed HTTP/2 connection can already saturate a worker under a small-response workload; adding client connections is not a substitute for adding measured worker capacity;
- measure CPU saturation, RSS per worker, accept distribution and socket contention together with throughput/latency;
- measure memory per worker;
- do not configure multiple workers for portable-only deployment;
- HTTP/3 multi-worker topology requires explicit reuse-port support.

Swoole/OpenSwoole worker sizing is host-owned and is configured through `SwooleOptions::workerCount`, not native `Server::workers`.

## 5. Admission

```php
use Infocyph\Runwire\Runtime\AdmissionPolicy;
use Infocyph\Runwire\RuntimeOptions;

$options = new RuntimeOptions(
    admission: new AdmissionPolicy(
        maxActiveRequests: 512,
        maxConcurrentConnections: 8_000,
        maxStreamsPerWorker: 2_000,
        retryAfterSeconds: 1,
    ),
);
```

A value of `0` disables that dimension. Tune from measured memory and latency rather than maximizing the numbers.

## 6. Request deadlines

```php
use Infocyph\Runwire\Runtime\RequestExecutionPolicy;

$options = new RuntimeOptions(
    requestExecution: new RequestExecutionPolicy(
        maxExecutionSeconds: 30.0,
    ),
);
```

Deadlines are monotonic and cooperative. Application code must check cancellation or use Runwire-aware coroutine waits to respond promptly.

## 7. Worker recycle

Prefork workers can retire at safe boundaries:

```php
use Infocyph\Runwire\Supervisor\WorkerRecyclePolicy;

$options = new RuntimeOptions(
    workerRecycle: new WorkerRecyclePolicy(
        maxRequests: 20_000,
        maxLifetimeSeconds: 3_600,
        maxMemoryBytes: 268_435_456,
        jitterRequests: 1_000,
        jitterSeconds: 120,
        gracefulTimeoutSeconds: 15.0,
    ),
);
```

All thresholds default disabled. Jitter avoids synchronized retirement. Portable mode rejects enabled worker-recycle thresholds because it has no replacement worker.

`maxMemoryBytes` measures PHP allocator memory through `memory_get_usage(true)` / `memory_get_peak_usage(true)`. It is not process RSS, cgroup usage, native-extension allocation, or kernel/socket memory. Use OS/container memory limits as the hard process-memory boundary.

## 8. Rolling reload

```php
use Infocyph\Runwire\Supervisor\ReloadPolicy;

$options = new RuntimeOptions(
    reload: new ReloadPolicy(
        maxUnavailable: 0,
        maxSurge: 1,
        replacementReadyTimeoutSeconds: 15.0,
        drainTimeoutSeconds: 30.0,
    ),
);
```

Safe order:

```text
spawn replacement
→ boot/warmup
→ wait ready
→ drain old worker
→ retire/reap old worker
→ next slot
```

Programmatic reload:

```php
$runtime->reload();
```

Reload is prefork-only. Host-owned runtimes use their host lifecycle/reload mechanisms and the capabilities exposed by Runwire.

## 9. Manual recycle

```php
$ok = $runtime->recycle('web', 0);
```

When the server also has HTTP/3 and QUIC capability, the matching HTTP/3 slot is coordinated too. `recycle()` returns `false` when no native supervisor is active.

## 10. Graceful stop

```php
$runtime->stop();
```

Forced:

```php
$runtime->stop(force: true);
```

Graceful sequence:

```text
stop accepting
→ protocol drain / GOAWAY
→ finish admission of previously accepted requests
→ application drain
→ finish admitted work
→ timeout
→ force remaining work
→ close resources
```

Native HTTP/1.1 connections accepted before stopping may finish their first exchange, including incomplete headers. Protocol draining prevents another keep-alive exchange. Application draining begins once these first requests have entered the application or their connections have closed; header/body deadlines and the recycling grace period remain enforced. Idle connections cannot extend recycling beyond that grace period.

HTTP/2 includes an accepted but incomplete HEADERS/CONTINUATION block in the GOAWAY boundary and lets it finish; higher stream IDs remain refused. HTTP/3 likewise lets previously accepted request streams finish decoding their headers before application draining begins. Both protocols retain their existing resource limits and drain deadlines. Native HTTP/3 continues advancing application tasks while draining.

Portable mode drains all attachments on its shared `SelectLoop` until complete or deadline expiry.

## 11. Application lifecycle hooks

```php
use Infocyph\Runwire\Runtime\ApplicationLifecycleHooks;
use Infocyph\Runwire\RuntimeContext;
use Infocyph\Runwire\Supervisor\Enum\ShutdownReason;

$hooks = new ApplicationLifecycleHooks(
    boot: static function (RuntimeContext $context): void {
        // Open worker-lifetime resources.
    },
    warmup: static function (RuntimeContext $context): void {
        // Verify dependencies before readiness.
    },
    drain: static function (RuntimeContext $context, ShutdownReason $reason): void {
        // Stop optional/new background work.
    },
    shutdown: static function (RuntimeContext $context, ShutdownReason $reason): void {
        // Close worker-lifetime resources.
    },
);

$options = new RuntimeOptions(applicationLifecycle: $hooks);
```

Warmup failure prevents readiness.

## 12. Request resetters

Persistent integrations should reset framework request-local state after every request, including handler failure, cancellation, and deadline paths.

```php
use Infocyph\Runwire\RequestContext;
use Infocyph\Runwire\Runtime\ApplicationLifecycleHooks;
use Infocyph\Runwire\Runtime\RequestResetterInterface;

final class ContainerResetter implements RequestResetterInterface
{
    public function reset(RequestContext $context): void
    {
        // Clear request-scoped framework/container/auth/tracing/transaction state.
    }
}

$hooks = new ApplicationLifecycleHooks(
    resetters: [new ContainerResetter()],
);
```

Cleanup failures should be observable but must not intentionally retain stale request state for the next request.

## 13. Native control socket

Prefork only:

```php
use Infocyph\Runwire\Control\ControlOptions;

$runtime = Runtime::create($options)
    ->control(new ControlOptions(
        path: '/run/runwire/control.sock',
        permissions: 0o600,
        maxRequestBytes: 8_192,
        maxResponseBytes: 1_048_576,
        maxConnections: 16,
        idleTimeoutSeconds: 10.0,
        lifetimeTimeoutSeconds: 60.0,
    ))
    ->listen($server);

$runtime->run();
```

Use an absolute Unix socket path, owner read/write access, no world access, and bounded request/response sizes. Portable mode rejects control configuration.

## 14. Development watcher

```php
use Infocyph\Runwire\Runtime\DevelopmentWatchPolicy;

$runtime = Runtime::create($options)
    ->watch(new DevelopmentWatchPolicy(
        enabled: true,
        paths: [__DIR__ . '/src', __DIR__ . '/config'],
        pollIntervalSeconds: 0.5,
        debounceSeconds: 0.25,
        maxFiles: 4_096,
    ))
    ->listen($server);

$runtime->run();
```

This is development-only and uses the prefork rolling-reload path. Portable mode rejects an enabled watcher.

## 15. Supervisor events

```php
use Infocyph\Runwire\Supervisor\SupervisorEvent;

$runtime->onEvent(
    static function (SupervisorEvent $event): void {
        error_log(sprintf(
            '[runwire] %s group=%s slot=%s pid=%s',
            $event->type->value,
            $event->group ?? '-',
            $event->slot === null ? '-' : (string) $event->slot,
            $event->pid === null ? '-' : (string) $event->pid,
        ));
    },
);
```

Keep handlers quick and bounded. Portable mode does not expose supervisor lifecycle events.

## 16. Privilege drop

Prefer starting the service under its final unprivileged account. If native prefork must start privileged, configure worker privilege dropping:

```php
use Infocyph\Runwire\Supervisor\PrivilegeDropPolicy;

$options = new RuntimeOptions(
    privilegeDrop: new PrivilegeDropPolicy(
        uid: 1001,
        gid: 1001,
    ),
);
```

For UID-based drops Runwire resolves the passwd entry, derives the base GID when needed, initializes supplementary groups, sets primary GID, sets UID, and verifies the effective identity. The transition runs before application bootstrap/readiness and fails closed on any incomplete step.

Native prefork selected as root without a privilege-drop policy emits a `SECURITY:` runtime-selection warning. Test filesystem/socket/certificate permissions under the final worker identity.

Portable mode rejects worker privilege-drop configuration; run the whole portable process as the intended unprivileged identity.

## 17. TLS / HTTP/2

```php
use Infocyph\Runwire\Network\TlsOptions;

$tls = new TlsOptions(
    localCertificate: '/etc/runwire/tls/fullchain.pem',
    privateKey: '/etc/runwire/tls/privkey.pem',
    handshakeTimeoutSeconds: 10.0,
);

$server = Server::http('0.0.0.0:8443', $handler)
    ->withTls($tls);
```

Defaults include TLS 1.2/1.3 server methods and ALPN `h2,http/1.1`. Runwire validates certificate/key paths and checks OpenSSL before binding.

## 18. HTTP/3

```php
$server = Server::http('0.0.0.0:8443', $handler)
    ->withTls($tls)
    ->withHttp3();
```

Production requirements:

1. supported `ext-quic` adapter;
2. supported QUIC-capable OpenSSL baseline;
3. certificate/private key;
4. UDP reachability on the listener port;
5. ALPN `h3`;
6. suitable QUIC/stream resource ceilings.

0-RTT application dispatch is disabled in Runwire 2.0. Explicit HTTP/3 without QUIC is a startup error. QUIC peer address changes must not be used as an authentication identity.

## 19. Adaptive protocol scheduling

The production default for HTTP/1.1, HTTP/2, and HTTP/3 is `FIXED`. `AUTO`, `LATENCY`, and `THROUGHPUT` remain supported explicit profiles, but they should be treated as workload-specific tuning rather than generic upgrades.

Production selection:

| Choice | Use when | Avoid when |
| --- | --- | --- |
| `FIXED` | Deterministic behavior is preferred, workload is mixed/unknown, or no representative repeated benchmark exists. | Only when a measured alternative clearly performs better for the deployment. |
| `LATENCY` | A deterministic latency bias improves measured interactive/RPC traffic. | Bulk/sustained workloads where the bias hurts throughput. |
| `THROUGHPUT` | A deterministic throughput bias improves measured sustained transfer performance. | Tail latency or small-response behavior regresses materially. |
| `AUTO` | Repeated tests cover steady load, transitions, p95/p99, CPU, RSS, fairness, and backpressure and remain inside your release budget. | Workloads without transition evidence, unstable measurements, or deployments that require strict determinism. |
| Per-protocol tuning | One protocol has a distinct traffic shape or bottleneck. | Applying one crossover profile blindly to H1/H2/H3. |
| H3 split tuning | Only inbound or outbound HTTP/3 behavior needs adaptation. | Changing both directions without evidence. |
| Hard limits | Change only after separate capacity testing. | Never raise them merely to compensate for an adaptive-policy choice. |

HTTP/1.1 chooses its default TCP_NODELAY behavior when each connection is attached. An explicit listener socket setting owns the decision and prevents the protocol strategy from overriding it:

```php
use Infocyph\Runwire\Network\ListenerOptions;

$listener = new ListenerOptions(
    socketContext: ['tcp_nodelay' => true],
);
```

AUTO policy validation:

| Setting | Requirement |
| --- | --- |
| Watermarks | Basis points of normalized load; `0 <= low < high <= 10000`. |
| Transition dwell | `transitionSamples >= 1`. |
| EWMA ratio | `1 <= numerator <= denominator <= intdiv(PHP_INT_MAX, 10000)`. |
| Invalid values | Constructor fails; values are not silently clamped. |

A standalone `new AdaptiveProtocolPolicy()` is an AUTO policy with generic watermarks. The protocol option objects deliberately supply FIXED release defaults and, where applicable, protocol-specific crossover values. When replacing a protocol policy object, specify the intended mode and preserve/tune its watermarks consciously.

See [Getting Started](getting-started.md#7-adaptive-protocol-scheduling) for complete construction examples and mode behavior.

## 20. SO_REUSEPORT

Reuse-port is explicit and off by default. Use only after validating platform support and load distribution.

Native HTTP/3 with multiple independently bound QUIC workers requires explicit reuse-port configuration.

## 21. Resource ceilings

Defaults are conservative. Constructor validation in the protocol limit objects is authoritative.

| Area | HTTP/1.1 | HTTP/2 | HTTP/3 |
| --- | ---: | ---: | ---: |
| Request body | 16 MiB | 16 MiB | 16 MiB |
| Header / field-section | 64 KiB | 64 KiB | 64 KiB |
| Header fields | 100 | 100 | 128 |
| Concurrent streams | n/a | 100 | 100 |
| Lifetime requests/streams per connection | 1,000 keep-alive requests | 10,000 streams | 10,000 request streams |
| Pending response / stream | transport-bounded | 1 MiB | 1 MiB |
| Pending response / connection | 1 MiB transport send buffer | 8 MiB | 8 MiB |
| Compression table | n/a | HPACK 4 KiB | QPACK max 64 KiB |
| Blocked compression streams | n/a | n/a | 32 |

Increase limits only after measuring memory, file-descriptor usage, and tail latency.

## 22. Backpressure

```php
$result = $writer->write($chunk);

if ($result->pressured()) {
    $writer->onDrain(
        static function (ResponseWriterInterface $writer): void {
            // Continue bounded production.
        },
    );
}
```

Do not respond to slow consumers by creating an unbounded application buffer.

## 23. Coroutine resource policy

Default limits:

| Coroutine policy | Default |
| --- | ---: |
| `maxTasks` | 1024 |
| `maxReadyBacklog` | 1024 |
| `maxFutureWaiters` | 1024 |
| `maxResumesPerTick` | 128 |
| `maxWaitersPerPrimitive` | 1024 |

Override intentionally:

```php
use Infocyph\Runwire\Coroutine\CoroutinePolicy;
use Infocyph\Runwire\Coroutine\CoroutineRuntime;

$coroutines = new CoroutineRuntime(
    policy: new CoroutinePolicy(
        maxTasks: 2_048,
        maxReadyBacklog: 2_048,
        maxFutureWaiters: 2_048,
        maxResumesPerTick: 256,
        maxWaitersPerPrimitive: 2_048,
    ),
);
```

Higher limits retain more task/waiter state.

## 24. Metrics and diagnostics

Runtime metrics:

```php
$snapshot = $context->snapshot();
```

Coroutine diagnostics:

```php
$diagnostics = $coroutines->diagnostics();
```

Keep labels fixed-cardinality. Do not emit request IDs, connection IDs, URLs, or arbitrary headers as unbounded metric labels.

Operational state should distinguish:

| State | Meaning |
| --- | --- |
| `live` | Process/runtime exists. |
| `ready` | Prepared to accept intended work. |
| `healthy` | Runtime is operating within its health contract. |
| `draining` | New work is restricted while admitted work completes. |

A PID existing proves only liveness; it is not sufficient evidence of readiness or health.

## 25. Production checklist

Before traffic, verify:

- service runs unprivileged or workers drop privilege before bootstrap/readiness;
- 64-bit PHP and required extensions; for high-connection native prefork HTTP, verify ext-event is installed and selected;
- the intended runtime driver is selected; Swoole/OpenSwoole deployments explicitly use `RuntimeDriver::SWOOLE` unless a custom integration supplies a reliable hosted signal;
- effective CPU/memory limits;
- file-descriptor limits;
- worker count versus memory footprint;
- admission thresholds;
- request/body/header/stream ceilings;
- graceful timeout versus real request duration;
- recycle thresholds from soak evidence;
- TLS key/certificate permissions;
- UDP firewall/LB rules for HTTP/3;
- control socket permissions if enabled;
- Unix-socket directory permissions if used;
- logs/metrics during reload and shutdown.

See [Runtime Security](security.md) for `disable_functions`, ProcessRunner allowlisting, systemd controls, container hardening, and persistent-state guidance.

## 26. Deployment acceptance

A production candidate should exercise:

- HTTP/1.1;
- HTTP/2 ALPN when enabled;
- HTTP/3 interoperability when enabled;
- request body/response streaming, including bounded `ResponseTransfer` use where adopted;
- native HTTP/1 WebSocket upgrade/message/close behavior where enabled;
- backpressure, including slow WebSocket readers;
- request cancellation/deadlines;
- hostile input/resource-limit rejection;
- overload rejection and recovery;
- prefork restart/reload/recycle;
- portable-native operation when PCNTL/POSIX are absent;
- graceful stop with active work;
- persistent-worker state isolation and memory/FD soak;
- hosted-runtime acceptance for the selected host, including explicit Swoole/OpenSwoole selection when applicable.

## Related documentation

- [2.0 migration guide](migration-2.0.md)

- [Getting started](getting-started.md)
- [Architecture and runtime contracts](architecture.md)
- [Runtime security and production hardening](security.md)
- [Coroutines and structured concurrency](coroutines.md)
- [Benchmark methodology](benchmarks.md)
