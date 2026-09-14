# Runwire 1.0 Deployment and Operations

This guide covers production topology, capability requirements, lifecycle, TLS/HTTP3 deployment, admission/resource limits, control/reload behavior, and operational tuning.

For complete first-run examples, see [Getting Started](getting-started.md). For ownership rules, see [Architecture](architecture.md).

## 1. Production baseline

Hard package requirement:

```text
64-bit PHP ^8.4
```

Recommended native prefork extensions:

```text
ext-pcntl
ext-posix
```

Optional capabilities:

```text
ext-openssl       TLS / HTTP/2 ALPN
ext-quic          native QUIC / HTTP/3
ext-swoole        Swoole host integration
ext-openswoole    OpenSwoole host integration
ext-sockets       optional socket features
ext-zend-opcache  bytecode cache
```

## 2. Correct ownership model

Runwire-owned native server:

```php
Runtime::create($options)
    ->listen(Server::http('0.0.0.0:8080', $handler))
    ->run();
```

Host-owned server:

```php
Runtime::create($options)->serve($handler);
```

or:

```php
Runtime::create($options)->serveApplication($factory);
```

Do not combine `listen()` with host-owned serving.

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

Portable mode keeps ordinary native serving available when PCNTL/POSIX are missing; it is not a substitute supervisor.

### Pre-release portable caveat

The active 1.0 plan still tracks hard-fail validation for:

- explicit `workers > 1` without prefork;
- enabled worker-recycle thresholds without replacement capability;
- explicit HTTP/3 configuration when QUIC is unavailable.

Do not rely on those unsupported combinations until the plan item is closed and exact-head certified.

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
- measure memory per worker;
- do not configure multiple workers for portable-only deployment;
- HTTP/3 multi-worker topology requires explicit reuse-port support.

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

All thresholds default disabled. Jitter avoids synchronized retirement. Portable mode does not own worker replacement.

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

Reload is prefork-only.

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
→ application drain
→ protocol drain / GOAWAY
→ finish admitted work
→ timeout
→ force remaining work
→ close resources
```

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

Persistent integrations should reset framework request-local state after every request.

```php
use Infocyph\Runwire\RequestContext;
use Infocyph\Runwire\Runtime\ApplicationLifecycleHooks;
use Infocyph\Runwire\Runtime\RequestResetterInterface;

final class ContainerResetter implements RequestResetterInterface
{
    public function reset(RequestContext $context): void
    {
        // Clear request-scoped framework/container state.
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

```php
use Infocyph\Runwire\Supervisor\PrivilegeDropPolicy;

$options = new RuntimeOptions(
    privilegeDrop: new PrivilegeDropPolicy(
        uid: 1001,
        gid: 1001,
    ),
);
```

Runwire validates this during runtime selection. It requires native prefork plus supported POSIX identity operations and sufficient master permissions.

Test filesystem/socket/certificate permissions under the final worker identity.

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

0-RTT application dispatch is disabled in 1.0. QUIC peer address changes must not be used as an authentication identity.

## 19. SO_REUSEPORT

Reuse-port is explicit and off by default. Use only after validating platform support and load distribution.

Native HTTP/3 with multiple independently bound QUIC workers requires explicit reuse-port configuration.

## 20. Resource ceilings

Defaults are conservative. Constructor validation in the protocol limit objects is authoritative.

| Area | HTTP/1.1 | HTTP/2 | HTTP/3 |
| --- | ---: | ---: | ---: |
| Request body | 16 MiB | 16 MiB | 16 MiB |
| Header / field-section | 64 KiB | 64 KiB | 64 KiB |
| Header fields | 100 | 100 | 128 |
| Concurrent streams | n/a | 100 | 100 |
| Lifetime request streams | bounded | 10,000 | 10,000 |
| Pending response / stream | bounded writer | 1 MiB | 1 MiB |
| Pending response / connection | bounded connection buffer | 8 MiB | 8 MiB |
| Compression table | n/a | HPACK 4 KiB | QPACK max 64 KiB |
| Blocked compression streams | n/a | n/a | 32 |

Increase limits only after measuring memory, file-descriptor usage, and tail latency.

## 21. Backpressure

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

## 22. Coroutine resource policy

Default limits:

```text
maxTasks                1024
maxReadyBacklog         1024
maxFutureWaiters        1024
maxResumesPerTick       128
maxWaitersPerPrimitive  1024
```

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

## 23. Metrics and diagnostics

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

```text
live
ready
healthy
draining
```

A PID existing is not sufficient evidence of readiness or health.

## 24. Production checklist

Before traffic, verify:

- 64-bit PHP and required extensions;
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

## 25. Deployment acceptance

A production candidate should exercise:

- HTTP/1.1;
- HTTP/2 ALPN when enabled;
- HTTP/3 interoperability when enabled;
- request body/response streaming;
- backpressure;
- request cancellation/deadlines;
- overload rejection and recovery;
- prefork restart/reload/recycle;
- graceful stop with active work;
- persistent-worker memory/FD soak;
- hosted-runtime acceptance for the selected host.

## Related documentation

- [Getting started](getting-started.md)
- [Architecture and runtime contracts](architecture.md)
- [Coroutines and structured concurrency](coroutines.md)
- [Benchmark methodology](benchmarks.md)
- [Runwire 1.0 launch plan](plans/runwire-1.0-foundation-3-launch-plan.md)
