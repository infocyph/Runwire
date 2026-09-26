# Runwire

A high-performance, framework-agnostic process and network runtime for PHP.

Runwire provides the low-level runtime boundary for process supervision, event loops, native network servers, HTTP/1.1/2/3, host-runtime adaptation, structured coroutines, bounded lifecycle management, and production diagnostics. It is designed to be embedded by frameworks and applications rather than becoming an application framework itself.

Runwire 2.0 is the next major runtime line. Consumers upgrading from 1.x should review the 2.0 migration guide before rollout.

## Requirements

Hard package requirements:

- 64-bit PHP `^8.4`;
- Composer.

Install:

```bash
composer require infocyph/runwire
```

Optional runtime capabilities:

| Extension/capability | Enables |
| --- | --- |
| `ext-pcntl` + `ext-posix` | native prefork supervision, reload, worker replacement/recycle, signals, control operations, privilege reduction |
| `ext-openssl` | native TLS and HTTP/2 ALPN |
| `ext-quic` | native QUIC / HTTP/3 |
| `ext-swoole` / `ext-openswoole` | Swoole/OpenSwoole host runtime integration; select `RuntimeDriver::SWOOLE` explicitly when using this host adapter |
| `ext-sockets` | optional low-level socket features |
| OPcache | persistent bytecode caching |

Without PCNTL/POSIX, native CLI uses Runwire's portable single-process runtime. Prefork-only capabilities are not advertised there.

## Native HTTP quick start

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

Run:

```bash
php server.php
curl http://127.0.0.1:8080/
```

## Runtime modes

`RuntimeDriver::AUTO` detects active hosted environments and native CLI capability. The built-in environment probe recognizes active FrankenPHP, RoadRunner, and FPM hosts; otherwise eligible CLI execution resolves to native Runwire. Merely installing Swoole/OpenSwoole does not mark the process as an active Swoole host, so use explicit `RuntimeDriver::SWOOLE` selection when Runwire should use its Swoole/OpenSwoole host adapter.

| Runtime | Listener/wire owner | Application lifetime | Worker pool |
| --- | --- | --- | --- |
| Native prefork | Runwire | persistent | Runwire |
| Native portable | Runwire | persistent | single process |
| FPM | web server / FPM | request-scoped from Runwire boundary | host |
| FrankenPHP | FrankenPHP | host/mode dependent | host |
| RoadRunner | RoadRunner | persistent | host |
| Swoole/OpenSwoole | Swoole/OpenSwoole | persistent | host |

Native listeners use:

```php
Runtime::create()->listen($server)->run();
```

Auto-detected host-owned runtimes use:

```php
Runtime::create()->serve($handler);
```

or:

```php
Runtime::create()->serveApplication($applicationFactory);
```

Swoole/OpenSwoole uses the same host-owned serving API, but select the driver explicitly:

```php
use Infocyph\Runwire\Runtime;
use Infocyph\Runwire\Runtime\Enum\RuntimeDriver;
use Infocyph\Runwire\RuntimeOptions;
use Infocyph\Runwire\SwooleOptions;

$options = new RuntimeOptions(
    driver: RuntimeDriver::SWOOLE,
    swoole: new SwooleOptions(
        host: '127.0.0.1',
        port: 9501,
    ),
);

Runtime::create($options)->serve($handler);
```

Either supported Swoole-family extension can back `RuntimeDriver::SWOOLE`. Explicit selection fails startup when neither runtime is available.

Do not combine host-owned serving with a competing Runwire listener.

### Portable native contract

Portable native is intentionally not a substitute supervisor:

```text
workers 0 or 1            one process
workers > 1               startup error
enabled recycle threshold startup error
control endpoint           startup error
development watcher        startup error
lifecycle listener         startup error
worker privilege drop      startup error
HTTP/3 without QUIC        startup error
```

HTTP/1.1, framed TCP/Unix, and UDP remain available when their platform capabilities are present. External supervision owns process restart/replacement.

## Native protocols

Runwire 2.0 provides:

- HTTP/1.1 over TCP/TLS;
- HTTP/2 over TLS ALPN with HTTP/1.1 fallback;
- HTTP/3 over QUIC when the supported QUIC capability is available;
- RFC 6455 WebSocket upgrade over native HTTP/1.1;
- framed TCP and Unix-domain stream servers;
- UDP datagram servers.

All native HTTP versions normalize to the same application-facing `HttpRequest` / `ResponseWriterInterface` contract.

### TLS / HTTP/2

```php
use Infocyph\Runwire\Network\TlsOptions;

$tls = new TlsOptions(
    localCertificate: __DIR__ . '/certs/server.crt',
    privateKey: __DIR__ . '/certs/server.key',
);

$server = Server::http('0.0.0.0:8443', $handler)
    ->withTls($tls);
```

Default ALPN is `h2,http/1.1`. Explicit TLS configuration fails when OpenSSL capability is unavailable; it never becomes plaintext silently.

### HTTP/3

```php
$server = Server::http('0.0.0.0:8443', $handler)
    ->withTls($tls)
    ->withHttp3();
```

HTTP/3 requires TLS plus the supported QUIC capability. Explicit HTTP/3 without QUIC fails startup; it is never silently ignored. 0-RTT application dispatch is disabled in Runwire 2.0.

### Native HTTP/1 WebSocket

```php
use Infocyph\Runwire\Http\HttpRequest;
use Infocyph\Runwire\Http\ResponseWriterInterface;
use Infocyph\Runwire\WebSocket\WebSocketMessage;
use Infocyph\Runwire\WebSocket\WebSocketSession;
use Infocyph\Runwire\WebSocket\WebSocketUpgrade;

$server = Server::http(
    '0.0.0.0:8080',
    static function (HttpRequest $request, ResponseWriterInterface $writer): void {
        $session = WebSocketUpgrade::accept(
            $request,
            $writer,
            originPolicy: static fn(string $origin, HttpRequest $request): bool =>
                $origin === 'https://example.com',
        );
        if (!$session instanceof WebSocketSession) {
            return;
        }

        $session->onMessage(
            static function (WebSocketSession $session, WebSocketMessage $message): void {
                $result = $message->binary
                    ? $session->sendBinary($message->data)
                    : $session->sendText($message->data);

                if (!$result->accepted()) {
                    $session->close(1011, 'write rejected');
                }
            },
        );
    },
);
```

Native WebSocket serving is intentionally HTTP/1.1-only in 2.0. Compression and HTTP/2/HTTP/3 extended CONNECT are not advertised. Browser requests carrying `Origin` require an explicit application origin policy; that policy complements authentication rather than replacing it.

## Framed TCP example

```php
use Infocyph\Runwire\Protocol\FramedConnection;
use Infocyph\Runwire\Protocol\LineCodec;
use Infocyph\Runwire\StreamServer;

$server = StreamServer::tcp(
    '127.0.0.1:9000',
    static fn(): LineCodec => new LineCodec(),
    static function (string $frame, FramedConnection $connection): void {
        $connection->send('echo:' . $frame);
    },
);

Runtime::create()->listen($server)->run();
```

## Structured coroutines

Runwire includes a lightweight structured-concurrency runtime built on PHP `Fiber` and `LoopInterface`. Coroutine request handlers can also use `ResponseTransfer::stream()` to copy an already-authorized stream into a response with bounded chunks, cancellation, cooperative yielding, and writer backpressure.

```php
use Infocyph\Runwire\Coroutine\CoroutineRuntime;
use Infocyph\Runwire\Coroutine\CoroutineScope;

$coroutines = new CoroutineRuntime();

$result = $coroutines->run(
    static function (CoroutineScope $scope): array {
        $left = $scope->spawn(static function () use ($scope): string {
            $scope->sleep(0.010);
            return 'left';
        });

        $right = $scope->spawn(static fn(): string => 'right');

        return [$left->await(), $right->await()];
    },
);
```

Structured ownership means a scope does not silently finish while owned children remain live. Cancellation and deadlines propagate through the ownership tree.

Available scheduler-aware primitives include:

- tasks and nested groups;
- futures/deferred values;
- bounded channels;
- semaphores and mutexes;
- barriers;
- task-local state;
- cooperative sleep/yield;
- readable/writable stream waiting;
- request-scoped coroutine execution;
- worker-generation background work;
- coroutine-friendly `AsyncConnection` adaptation.

Runwire does **not** make arbitrary blocking PHP APIs asynchronous.

## Runtime and request context

`RuntimeContext` contains immutable worker/application-lifetime facts:

```text
driver / mode
worker slot / generation / PID
persistence / concurrency
listener / event-loop / worker-pool ownership
resolved capabilities
runtime metrics
```

`RequestContext` contains request-lifetime state:

```text
request ID
monotonic start/deadline
cancellation token
bounded request attributes
owning RuntimeContext
```

Framework integrations should use capability checks instead of driver-name branching for generic behavior:

```php
use Infocyph\Runwire\Runtime\Enum\RuntimeCapability;

if ($context->supports(RuntimeCapability::PERSISTENT)) {
    // worker-lifetime application state is allowed
}

$context->requireCapability(RuntimeCapability::RUNWIRE_COROUTINES);
```

## Application lifecycle

Managed applications follow:

```text
boot
→ warmup
→ ready
→ handle
→ reset
→ ...
→ drain
→ shutdown
```

Frameworks can implement `RuntimeApplicationFactoryInterface` so application construction happens after the concrete runtime context/capabilities are known.

Request cleanup is part of the persistent-runtime security boundary. Framework adapters must reset framework-owned request-local state after every request, including failure/cancellation/deadline paths.

## Worker lifecycle

Native prefork supports:

- readiness tracking;
- bounded startup/readiness timeouts;
- process restart/backoff;
- rolling reload;
- worker recycle/replacement;
- lifecycle events;
- optional control socket;
- optional development watcher;
- optional privilege reduction.

When UID-based privilege reduction is configured, Runwire resolves the target account, initializes supplementary groups, sets GID before UID, verifies the final effective identity, and only then proceeds to application bootstrap/readiness. A partial transition fails startup.

Native prefork running as root without worker privilege dropping reports a `SECURITY:` runtime-selection warning. Prefer starting the service unprivileged whenever possible.

## Resource safety

Runwire is designed around bounded state:

- admission limits;
- listener/connection limits;
- request-body/header limits;
- HTTP/2/HTTP/3 stream limits;
- response buffering and backpressure;
- HPACK/QPACK limits;
- event-loop work budgets;
- coroutine tasks/backlogs/waiters;
- diagnostics/status payloads;
- restart/reload/recycle policies.

Treat limit increases as capacity-planning decisions and verify them with soak/load tests.

`WorkerRecyclePolicy::maxMemoryBytes` is based on PHP allocator memory, not process RSS/cgroup/native-extension/kernel memory. Use OS/container memory limits as the hard process-memory boundary.

## Process execution

`ProcessRunner` executes validated argv directly with shell bypass and does not require PCNTL. Its default `allowedExecutables: null` means any validated **absolute** executable path is permitted. Security-sensitive consumers should supply an explicit executable allowlist and bound environment, cwd, stdin, output, and timeout policies.

See the security guide before exposing process execution to application-controlled input.

## Performance

Repository benchmark artifacts are regression/workload evidence, not a universal runtime ranking.

Meaningful cross-runtime comparisons require equivalent:

- hardware and OS;
- PHP/runtime versions;
- protocol/TLS configuration;
- worker count and concurrency;
- workload;
- duration and instrumentation.

Report throughput together with p50/p95/p99 latency, errors, CPU, and RSS.

## Documentation

Start here for complete examples and operational guidance:

- [`docs/getting-started.md`](docs/getting-started.md) — complete native HTTP, TLS/HTTP2, HTTP3, TCP/Unix, UDP, hosted-runtime, explicit Swoole/OpenSwoole selection, application-factory, capability, and coroutine examples.
- [`docs/architecture.md`](docs/architecture.md) — runtime selection, ownership boundaries, capability model, contexts, lifecycle, networking, protocol, coroutine, observability, and security contracts.
- [`docs/deployment.md`](docs/deployment.md) — production topology, host selection, worker sizing, admission, deadlines, recycle/reload, control/watch, privilege drop, TLS/HTTP3, backpressure, resource limits, and deployment acceptance.
- [`docs/security.md`](docs/security.md) — least privilege, persistent-state isolation, ProcessRunner policy, `disable_functions`, resource ceilings, and systemd/container hardening.
- [`docs/coroutines.md`](docs/coroutines.md) — full structured-concurrency API with tasks, failure modes, deadlines, channels, futures, semaphore, mutex, barrier, task-local state, request integration, background work, and `AsyncConnection` examples.
- [`docs/benchmarks.md`](docs/benchmarks.md) — benchmark layers, local commands, HTTP/3 transport measurement, release evidence, peer-comparison schema, and integrity rules.
- [`docs/migration-2.0.md`](docs/migration-2.0.md) — required 1.x to 2.0 consumer migration checks and behavioral contract changes.
- [`docs/plans/runwire-security-performance-plan.md`](docs/plans/runwire-security-performance-plan.md) — 2.0 security, lifecycle, performance, and release-certification tracker.

## Development

Install development dependencies:

```bash
composer install
```

Run repository QA:

```bash
composer ic:ci
```

Run the automated formatting/refactor processing pipeline:

```bash
composer ic:process
```

The CI matrix covers supported PHP versions/dependency modes, static analysis, clean production installation, genuine no-PCNTL portable-native acceptance, benchmarks, Swoole/OpenSwoole acceptance, and dedicated QUIC/HTTP/3 interoperability/soak lanes.

## License

Runwire is released under the MIT License.
