# Runwire

A high-performance, framework-agnostic process and network runtime for PHP.

Runwire provides the low-level runtime boundary for process supervision, event loops, network servers, HTTP/1.1, HTTP/2, HTTP/3, host-runtime adaptation, structured coroutines, bounded lifecycle management, and production diagnostics. It is designed to be embedded by frameworks and applications rather than becoming an application framework itself.

Runwire 1.0 is being finalized for the Foundation 3 launch.

## What Runwire is

Runwire owns runtime mechanics:

- native HTTP, framed TCP/Unix stream, and UDP servers;
- native single-process and prefork worker execution;
- worker readiness, graceful shutdown, reload, recycle, restart, and supervision;
- HTTP/1.1, HTTP/2, QUIC/HTTP/3, HPACK, and QPACK runtime concerns;
- TLS, listener, connection, protocol, backpressure, and admission boundaries;
- immutable runtime context and isolated request context;
- request deadlines and cooperative cancellation;
- persistent application lifecycle contracts;
- PHP Fiber structured concurrency through a Runwire scheduler;
- host integration for FPM, FrankenPHP, RoadRunner, Swoole, and OpenSwoole;
- fixed-cardinality metrics, diagnostics, and bounded control/status state;
- capability detection so integrations can depend on behavior instead of driver names.

Runwire intentionally does **not** provide routing, dependency injection, an ORM, templating, application configuration, business-domain conventions, or a message broker.

## Requirements

Hard package requirements:

- PHP `^8.4`;
- 64-bit PHP (`php-64bit`).

The 64-bit requirement is intentional. Runwire uses monotonic nanosecond deadlines and QUIC/HTTP/3 integer ranges that are not safely representable by 32-bit PHP integers.

Install with Composer:

```bash
composer require infocyph/runwire
```

### Optional runtime capabilities

Runwire uses a capability-first model. Optional extensions improve or unlock specific runtime behavior but are not all required for ordinary installation.

| Capability | What it enables | Behavior when unavailable |
| --- | --- | --- |
| `ext-pcntl` + `ext-posix` | Native prefork workers, signals, reload, recycle supervision, control operations, privilege reduction | Native CLI falls back to a portable single-process runtime when the requested topology permits it |
| `ext-openssl` | Native TLS and HTTP/2 ALPN | Plain HTTP remains available; explicit TLS configuration fails clearly |
| `ext-quic` | Native QUIC and HTTP/3 | HTTP/1.1 and HTTP/2 remain available; explicit HTTP/3 configuration fails clearly |
| `ext-swoole` / `ext-openswoole` | Hosted Swoole/OpenSwoole runtime integration | Other available runtime modes are selected normally |
| `ext-event` | Consumer-provided accelerated `LoopInterface` adapters | Runwire's portable `SelectLoop` remains available |
| `ext-sockets` | Optional low-level socket features and tuning | Core stream APIs continue where supported by PHP streams |
| OPcache | Persistent bytecode caching | Runtime continues without the optimization |

Runwire never silently downgrades an explicit security or topology contract. For example, configured TLS does not become plaintext, configured HTTP/3 does not silently become HTTP/1.1, and an explicitly unsupported multi-worker topology does not silently collapse to one worker.

## Runtime model

`RuntimeDriver::AUTO` is the default. Runwire detects the current environment and chooses the appropriate execution model.

| Runtime | Listener / wire owner | Application lifetime | Runwire worker pool |
| --- | --- | --- | --- |
| Native prefork | Runwire | persistent | yes |
| Native portable | Runwire | persistent | single process |
| FPM | Web server / PHP-FPM | request scoped from Runwire's boundary | no |
| FrankenPHP | FrankenPHP | persistent in worker mode | host owned |
| RoadRunner | RoadRunner | persistent | host owned |
| Swoole / OpenSwoole | Swoole/OpenSwoole | persistent | host owned |

Hosted runtimes retain listener, event-loop, and worker-pool ownership. Runwire adapts application lifecycle and request execution without starting a competing network stack inside the host.

## Quick start: native HTTP

```php
<?php

declare(strict_types=1);

use Infocyph\Runwire\Http\HttpRequest;
use Infocyph\Runwire\Http\ResponseWriterInterface;
use Infocyph\Runwire\Runtime;
use Infocyph\Runwire\Server;

require __DIR__ . '/vendor/autoload.php';

$server = Server::http(
    '127.0.0.1:8080',
    static function (HttpRequest $request, ResponseWriterInterface $writer): void {
        $writer->end("Hello from Runwire\n");
    },
);

Runtime::create()
    ->listen($server)
    ->run();
```

Runwire accepts addresses such as `127.0.0.1:8080` and normalizes native TCP listener handling internally.

### Worker count

Every managed server has a worker count:

```php
$server = Server::http('0.0.0.0:8080', $handler)
    ->withWorkers(4);
```

`workers: 0` requests automatic sizing. On native prefork deployments, Runwire uses detected effective resources, including supported cgroup CPU limits. A portable native runtime remains single-process.

Native multi-worker supervision requires the prefork capability set. If PCNTL/POSIX are unavailable, ordinary single-process native serving can still run, while explicitly prefork-only behavior fails with a clear capability error.

## Hosted runtime usage

For FPM, FrankenPHP, RoadRunner, or Swoole/OpenSwoole, use the host-owned serving path rather than registering a Runwire listener:

```php
<?php

declare(strict_types=1);

use Infocyph\Runwire\Http\HttpRequest;
use Infocyph\Runwire\Http\ResponseWriterInterface;
use Infocyph\Runwire\Runtime;

require __DIR__ . '/vendor/autoload.php';

Runtime::create()->serve(
    static function (HttpRequest $request, ResponseWriterInterface $writer): void {
        $writer->end('Hello from the host runtime');
    },
);
```

`Runtime::run()` is for Runwire-owned native listeners. `Runtime::serve()` and `Runtime::serveApplication()` are for host-owned runtimes.

## Framework and persistent-application integration

Framework integrations should prefer `RuntimeApplicationFactoryInterface`. The factory receives the resolved `RuntimeContext`, allowing an integration to validate runtime capabilities before constructing worker-local state.

Native listener integration:

```php
$server = Server::httpApplicationFactory(
    '0.0.0.0:8080',
    $applicationFactory,
);

Runtime::create()
    ->listen($server)
    ->run();
```

Hosted integration:

```php
Runtime::create()->serveApplication($applicationFactory);
```

A managed persistent application follows this lifecycle:

```text
boot
→ warmup
→ ready
→ handle request
→ reset request state
→ ...
→ drain
→ shutdown
```

Boot and warmup happen before readiness. Reset runs after request handling, including failure paths. Drain stops optional/new work while already-admitted work is allowed to finish within configured bounds. Shutdown runs exactly once for the application instance.

## TLS and HTTP/2

Native TLS uses `TlsOptions`. Its default ALPN list is `h2,http/1.1`, allowing HTTP/2 negotiation with HTTP/1.1 fallback.

```php
use Infocyph\Runwire\Network\TlsOptions;
use Infocyph\Runwire\Runtime;
use Infocyph\Runwire\Server;

$tls = new TlsOptions(
    localCertificate: __DIR__ . '/certs/server.crt',
    privateKey: __DIR__ . '/certs/server.key',
);

$server = Server::http('0.0.0.0:8443', $handler)
    ->withTls($tls);

Runtime::create()
    ->listen($server)
    ->run();
```

Certificate and private-key files are validated when TLS options are created. If TLS is explicitly configured but the required OpenSSL capability is unavailable, startup fails rather than serving plaintext.

## HTTP/3 and QUIC

HTTP/3 is opt-in and uses the same application-visible `HttpRequest` / `ResponseWriterInterface` contract as HTTP/1.1 and HTTP/2.

```php
use Infocyph\Runwire\Network\TlsOptions;
use Infocyph\Runwire\Runtime;
use Infocyph\Runwire\Server;

$tls = new TlsOptions(
    localCertificate: __DIR__ . '/certs/server.crt',
    privateKey: __DIR__ . '/certs/server.key',
);

$server = Server::http('0.0.0.0:8443', $handler)
    ->withTls($tls)
    ->withHttp3();

Runtime::create()
    ->listen($server)
    ->run();
```

Native HTTP/3 requires:

- a supported QUIC capability (`ext-quic` in the current native adapter);
- TLS certificate and key material;
- a QUIC-capable OpenSSL baseline required by the adapter;
- UDP reachability for the selected HTTP/3 port;
- ALPN `h3` support.

0-RTT application dispatch is disabled in Runwire 1.0. QUIC cryptography, congestion control, and loss recovery remain responsibilities of the native QUIC engine rather than PHP application code.

For native HTTP/3 with multiple independently bound workers, explicit `SO_REUSEPORT` support is required. It is deliberately disabled by default.

## HTTP response streaming and backpressure

`ResponseWriterInterface` supports staged response output:

```php
$writer->start(200);
$writer->write('first chunk');
$writer->write('second chunk');
$writer->end('done');
```

`start()`, `write()`, and `end()` return `WriteResult`. Consumers that stream large responses should respect write pressure and use `onDrain()` rather than building unbounded application buffers.

Runwire keeps transport/protocol buffering bounded and applies overload behavior locally to the affected request, stream, or connection wherever the protocol allows it.

## TCP and Unix framed servers

Runwire can manage framed non-HTTP stream protocols. `StreamServer` supports TCP and Unix-domain sockets through a `FrameCodecInterface`.

A line-delimited TCP echo server:

```php
use Infocyph\Runwire\Protocol\FramedConnection;
use Infocyph\Runwire\Protocol\LineCodec;
use Infocyph\Runwire\Runtime;
use Infocyph\Runwire\StreamServer;

$server = StreamServer::tcp(
    '127.0.0.1:9000',
    static fn(): LineCodec => new LineCodec(),
    static function (string $frame, FramedConnection $connection): void {
        $connection->send($frame);
    },
);

Runtime::create()
    ->listen($server)
    ->run();
```

Runwire also provides `StreamServer::unix()` for framed Unix-domain sockets and `DatagramServer::udp()` for managed UDP datagram handlers.

## Structured coroutines

Runwire includes a lightweight structured-concurrency runtime built on PHP `Fiber` and `LoopInterface`.

```php
use Infocyph\Runwire\Coroutine\CoroutineRuntime;
use Infocyph\Runwire\Coroutine\CoroutineScope;

$runtime = new CoroutineRuntime();

$result = $runtime->run(function (CoroutineScope $scope): array {
    $left = $scope->spawn(static fn(): string => 'left');
    $right = $scope->spawn(static fn(): string => 'right');

    return [$left->await(), $right->await()];
});
```

The ownership model is structured:

```text
runtime
├── request root scope
│   └── nested groups
│       └── tasks
└── worker background scope
    └── tasks
```

A scope does not finish while owned children are still live. Cancellation and deadlines propagate down the ownership tree. Fail-fast task groups cancel and join siblings before propagating the primary failure.

Runwire coroutines do **not** make arbitrary blocking PHP APIs asynchronous. PDO, filesystem operations, third-party synchronous HTTP clients, and CPU-heavy loops still block the current worker unless the consumer selects a non-blocking integration or yields/offloads appropriately.

Available scheduler-aware primitives include:

- bounded channels;
- futures/deferred values;
- semaphores;
- mutexes;
- barriers;
- task-local state;
- cooperative sleep/yield;
- readable/writable stream suspension;
- cancellation-aware deadlines.

See [`docs/coroutines.md`](docs/coroutines.md) for the complete coroutine contract.

## RuntimeContext and RequestContext

`RuntimeContext` represents immutable worker/application-lifetime facts such as:

- selected driver and mode;
- worker slot, generation, and PID;
- persistence and concurrency behavior;
- listener/event-loop/worker-pool ownership;
- detected capabilities;
- runtime metrics access.

`RequestContext` represents isolated request-lifetime state:

- bounded request ID;
- monotonic start time;
- request deadline;
- cancellation token;
- bounded request-local attributes;
- owning `RuntimeContext`.

Request-local state is cleared at request completion. Persistent runtimes therefore do not intentionally leak request-owned attributes, cancellation state, timers, or structured tasks into the next request.

Framework code should prefer `RuntimeContext::supports()` / `requireCapability()` over branching on concrete driver names when deciding whether a generic behavior is available.

## Cancellation and deadlines

Cancellation is cooperative and scoped. It may originate from:

- request deadline expiry;
- transport or stream cancellation;
- host cancellation;
- worker drain/shutdown;
- structured parent cancellation.

HTTP/2 and HTTP/3 stream cancellation remains local to the stream and does not cancel unrelated sibling streams.

All internal elapsed-time/deadline logic uses monotonic time. Long-running application loops should periodically check their cancellation token when prompt shutdown or timeout response matters.

## Worker lifecycle

Native prefork mode provides:

- worker readiness tracking;
- bounded startup and readiness timeouts;
- restart/backoff policy;
- graceful stop;
- worker recycle;
- rolling reload;
- worker lifecycle events;
- optional control endpoint;
- optional development reload watcher;
- task/service/custom worker roles.

A rolling replacement follows the safe order:

```text
spawn replacement
→ boot/warmup
→ wait for readiness
→ drain old worker
→ retire old worker
→ continue with next slot
```

Healthy old-generation capacity is not intentionally removed before replacement readiness.

Portable single-process native mode keeps the same network/application contracts but does not pretend to provide prefork-only supervision features.

## Worker recycling

Persistent workers can be retired using `WorkerRecyclePolicy` thresholds such as request count, lifetime, and memory.

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

Recycle thresholds are soft retirement triggers evaluated at safe boundaries; they are not intended to kill active application work immediately.

## Admission and resource bounds

Runwire uses bounded defaults throughout its runtime. Important protection areas include:

- active requests and connections;
- HTTP/2 and HTTP/3 streams;
- request and response buffering;
- header/field-section size and count;
- HPACK/QPACK state;
- event-loop work per tick;
- coroutine tasks and waiters;
- diagnostics/status payloads;
- restart/reload/recycle behavior.

`RuntimeOptions::admission` provides application-level admission above protocol/transport limits. Overload is rejected locally where possible rather than creating an unbounded queue.

Default protocol limits are intentionally conservative. Typical defaults include a 16 MiB request-body ceiling, 64 KiB header/field-section ceilings, and 100 concurrent HTTP/2/HTTP/3 streams. Constructor validation in the protocol limit objects is authoritative.

## Graceful shutdown

Native shutdown follows a bounded drain sequence:

1. stop accepting new work;
2. enter HTTP/2/HTTP/3 drain state where applicable;
3. invoke application drain hooks;
4. allow admitted work to complete within configured bounds;
5. terminate remaining work according to shutdown policy;
6. close transport/runtime state and reap supervised children.

HTTP/3 portable attachment handling follows the same bounded lifecycle and continues QUIC polling during drain until completion or deadline expiry.

## Metrics and diagnostics

Runwire exposes fixed-cardinality metrics and bounded diagnostic snapshots rather than retaining per-request or per-connection history.

Runtime metrics cover areas such as:

- requests and failures;
- active/accepted/rejected connections;
- protocol streams;
- bytes read/written;
- backpressure and overload;
- worker state, age, and busy time;
- event-loop health;
- coroutine scheduler state;
- HTTP/2 and HTTP/3 protocol state.

Operational state distinguishes concepts such as `live`, `ready`, `healthy`, and `draining`; process existence alone is not treated as health.

The optional native control surface exposes bounded status and lifecycle actions such as status, reload, recycle, and stop when the selected topology supports them.

## Background work

Worker-scoped background work belongs to the worker generation, not to an HTTP request. `WorkerContext` supports bounded recurring/background execution such as:

- `every()` for named recurring work;
- `spawnBackground()` for structured generation-owned coroutine work.

When drain begins, new background admission stops and existing generation-owned work is cancelled/drained according to the configured grace period. Old-generation background tasks must not survive into replacement workers.

## Security and deployment behavior

Runwire favors explicit failure over unsafe implicit downgrade.

- TLS configuration requires TLS capability and valid certificate material.
- HTTP/3 configuration requires QUIC capability.
- Privilege-drop configuration requires the necessary POSIX identity operations.
- `SO_REUSEPORT` is off by default and validated when explicitly enabled.
- HTTP/3 0-RTT application dispatch is disabled in 1.0.
- Request/status diagnostics are bounded and should not expose arbitrary body/header/environment data.
- Peer address stability should not be used as an authentication boundary for QUIC connections.

Production worker counts, admission limits, protocol ceilings, recycle thresholds, and buffer watermarks should be tuned against measured application behavior rather than maximized blindly.

## Architecture boundaries

Runwire is intentionally low-level.

Within the wider Infocyph stack:

- **Runwire** owns process/network runtime mechanics;
- **Foundation** owns application/container semantics;
- **Webrick** owns higher-level application HTTP semantics;
- **Omnibus** owns messaging/queue semantics.

This separation keeps Runwire reusable outside those projects and prevents runtime code from accumulating framework-specific behavior.

## Performance

Runwire is designed around bounded work, persistent workers, explicit backpressure, low allocation pressure, and predictable lifecycle behavior.

Repository benchmark artifacts are regression/workload evidence, not a universal claim that Runwire is the fastest PHP runtime. Meaningful cross-runtime comparisons must use equivalent:

- hardware and operating system;
- PHP version and configuration;
- protocol and TLS settings;
- worker count and concurrency;
- application workload;
- connection behavior;
- measurement duration.

Report throughput together with latency percentiles, error rate, CPU, and RSS rather than relying on a single requests-per-second number.

See [`docs/benchmarks.md`](docs/benchmarks.md) for methodology and acceptance guidance.

## Development

The repository uses PHPForge for its development QA toolchain.

```bash
composer install
composer ic:ci
```

To run the repository's automated processing/fix pipeline:

```bash
composer ic:process
```

The CI matrix covers PHP 8.4 and 8.5, stable/lowest dependency combinations, static analysis, clean production installation, protocol testing, benchmarks, hosted Swoole/OpenSwoole acceptance, and dedicated QUIC/HTTP/3 interoperability lanes.

## Documentation

- [`docs/deployment.md`](docs/deployment.md) — runtime/capability ownership, lifecycle, reload/recycle, resource policy, HTTP/1.1/2/3, QUIC/QPACK, diagnostics, deployment, and tuning.
- [`docs/coroutines.md`](docs/coroutines.md) — structured concurrency, cancellation, synchronization, host-loop integration, async network adaptation, diagnostics, and tuning.
- [`docs/benchmarks.md`](docs/benchmarks.md) — benchmark methodology, evidence rules, and protocol/runtime performance measurement.
- [`docs/runwire-1.0-pr-readiness.md`](docs/runwire-1.0-pr-readiness.md) — implementation/readiness evidence for the 1.0 release line.
- [`docs/plans/runwire-1.0-foundation-3-launch-plan.md`](docs/plans/runwire-1.0-foundation-3-launch-plan.md) — canonical Runwire 1.0 development and release plan.

## License

Runwire is released under the MIT License.
