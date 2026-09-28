# Runwire 2.0 Getting Started

This guide takes a new consumer from installation to the main Runwire 2.0 serving modes using public APIs and complete examples.

## Install

Runwire requires 64-bit PHP `^8.4`.

```bash
composer require infocyph/runwire
```

Optional capabilities:

```text
ext-pcntl + ext-posix   native prefork supervision/reload/recycle
ext-event               scalable native HTTP loop for prefork workers; SelectLoop fallback otherwise
ext-openssl             TLS and HTTP/2 ALPN
ext-quic                native QUIC / HTTP/3
ext-swoole              Swoole host integration; select RuntimeDriver::SWOOLE explicitly
ext-openswoole          OpenSwoole host integration; select RuntimeDriver::SWOOLE explicitly
ext-sockets             optional socket features
ext-zend-opcache        bytecode cache
```

Native CLI serving remains available without PCNTL/POSIX through the portable single-process runtime.

## 1. Minimal native HTTP server

Create `server.php`:

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

Run and test:

```bash
php server.php
curl http://127.0.0.1:8080/
```

`Runtime::run()` is the Runwire-owned native listener path.

## 2. JSON response and request metadata

```php
<?php

declare(strict_types=1);

use Infocyph\Runwire\Http\Headers;
use Infocyph\Runwire\Http\HttpRequest;
use Infocyph\Runwire\Http\ResponseWriterInterface;
use Infocyph\Runwire\Runtime;
use Infocyph\Runwire\Server;

require __DIR__ . '/vendor/autoload.php';

$server = Server::http(
    '127.0.0.1:8080',
    static function (HttpRequest $request, ResponseWriterInterface $writer): void {
        $payload = json_encode([
            'method' => $request->method,
            'target' => $request->target,
            'protocol' => $request->version->value,
            'request_id' => $request->context->requestId,
            'peer' => $request->peerAddress,
            'user_agent' => $request->headers->first('user-agent'),
        ], JSON_THROW_ON_ERROR);

        $writer->start(
            200,
            Headers::fromArray([
                'content-type' => 'application/json; charset=utf-8',
                'cache-control' => 'no-store',
            ]),
        );
        $writer->end($payload . "\n");
    },
);

Runtime::create()->listen($server)->run();
```

Header helpers:

```php
$request->headers->has('authorization');
$request->headers->first('content-type');
$request->headers->all('cookie');
```

## 3. Incremental request body

`RequestBodyInterface` exposes buffered reads plus data/end notifications.

```php
use Infocyph\Runwire\Http\RequestBodyInterface;

$handler = static function (HttpRequest $request, ResponseWriterInterface $writer): void {
    $body = $request->body;
    $buffer = '';

    $readAvailable = static function () use ($body, &$buffer): void {
        while ($body->bufferedBytes() > 0) {
            $buffer .= $body->read(8192);
        }
    };

    $readAvailable();

    if ($body->eof()) {
        $writer->end($buffer);
        return;
    }

    $body->onData(
        static function (RequestBodyInterface $body) use ($readAvailable): void {
            $readAvailable();
        },
    );

    $body->onEnd(
        static function (RequestBodyInterface $body) use ($readAvailable, &$buffer, $writer): void {
            $readAvailable();
            $writer->end($buffer);
        },
    );
};
```

A framework adapter should normally own request-body consumption for its framework rather than registering competing consumers.

## 4. Streaming response and backpressure

```php
use Infocyph\Runwire\Http\Headers;

$writer->start(
    200,
    Headers::fromArray(['content-type' => 'text/plain; charset=utf-8']),
);

$result = $writer->write("chunk 1\n");

if ($result->pressured()) {
    $writer->onDrain(
        static function (ResponseWriterInterface $writer): void {
            $writer->write("chunk 2\n");
            $writer->end("done\n");
        },
    );
} else {
    $writer->write("chunk 2\n");
    $writer->end("done\n");
}
```

Do not accumulate an unbounded producer buffer while the transport is pressured.

### Transparent bounded response fast paths

For an implicit response such as:

```php
$writer->end('ok');
```

Runwire may use a bounded transport fast path without changing the application contract:

- HTTP/1.1 may serialize the implicit 200 response head and final body into one transport write when the complete wire response fits the currently available bounded send capacity.
- HTTP/2 may send an implicit 200 HEADERS+DATA response through its bounded initial-response path only when the scheduler is otherwise idle, the encoded response fits the active policy's one-shot budget, peer/header limits allow it, flow-control credit is available, and the transport has capacity.
- If any fast-path condition is not satisfied, Runwire falls back to the normal response path automatically.
- Explicit `start() / write() / end()`, chunked/streaming responses, response backpressure, declared Content-Length validation, and body-suppression semantics keep their normal behavior.

Applications should not add special buffering to force these paths; they are transparent runtime optimizations.

## 5. TLS and HTTP/2

```php
<?php

declare(strict_types=1);

use Infocyph\Runwire\Network\TlsOptions;
use Infocyph\Runwire\Runtime;
use Infocyph\Runwire\Server;

require __DIR__ . '/vendor/autoload.php';

$tls = new TlsOptions(
    localCertificate: __DIR__ . '/certs/server.crt',
    privateKey: __DIR__ . '/certs/server.key',
);

$server = Server::http('0.0.0.0:8443', $handler)
    ->withTls($tls);

Runtime::create()->listen($server)->run();
```

Test:

```bash
curl --http2 -k https://127.0.0.1:8443/
```

Default ALPN is `h2,http/1.1`. Configured TLS fails explicitly when OpenSSL support is unavailable; it never becomes plaintext silently.

## 6. HTTP/3

```php
<?php

declare(strict_types=1);

use Infocyph\Runwire\Http\Http3\Http3Options;
use Infocyph\Runwire\Network\TlsOptions;
use Infocyph\Runwire\Runtime;
use Infocyph\Runwire\Server;

require __DIR__ . '/vendor/autoload.php';

$tls = new TlsOptions(
    localCertificate: __DIR__ . '/certs/server.crt',
    privateKey: __DIR__ . '/certs/server.key',
);

$server = new Server(
    name: 'web',
    address: '0.0.0.0:8443',
    handler: $handler,
    tls: $tls,
    http3: new Http3Options(),
);

Runtime::create()->listen($server)->run();
```

Fluent equivalent:

```php
$server = Server::http('0.0.0.0:8443', $handler)
    ->withTls($tls)
    ->withHttp3();
```

HTTP/3 uses UDP/QUIC while the TCP listener continues to serve HTTP/1.1/HTTP/2. 0-RTT application dispatch is disabled in Runwire 2.0. Explicit HTTP/3 configuration without supported QUIC capability is a startup error; it is never silently ignored.

Most applications should enable HTTP/3 through `Server`/`Http3Options`. The lower-level `PhpQuicHttp3Worker` and `PhpQuicHttp3Connection` adapters are for integrations that already own ext-quic listener/connection objects; they require a negotiated `h3` connection, enforce the same HTTP/3 limits, and default both adaptive directions to FIXED. `PhpQuicHttp3Connection::adaptivePumpState()` exposes the current inbound adaptive state for diagnostics; `PhpQuicConnection::closed()` reports whether the native QUIC layer has already observed closure.

## 7. Adaptive protocol scheduling

Runwire 2.0 defaults HTTP/1.1, HTTP/2, and HTTP/3 to `AdaptivePolicyMode::FIXED`. Nothing needs to be configured to get the release-certified deterministic behavior.

The other modes are explicit opt-ins:

| Mode | Behavior |
| --- | --- |
| `FIXED` | Release default. Deterministic static scheduling; H1 keeps TCP_NODELAY enabled unless listener configuration explicitly owns it. |
| `LATENCY` | Deterministic latency-biased scheduling. |
| `THROUGHPUT` | Deterministic throughput-biased scheduling; H1 selects NODELAY off unless explicitly overridden by listener socket configuration. |
| `AUTO` | Dynamically crosses latency/balanced/throughput states from bounded protocol-local load samples. Use only after representative workload measurement. |

Each protocol owns its policy separately. HTTP/3 has independent inbound and outbound policies:

```php
use Infocyph\Runwire\Http\AdaptiveProtocolPolicy;
use Infocyph\Runwire\Http\Enum\AdaptivePolicyMode;
use Infocyph\Runwire\Http\Http1\Http1Limits;
use Infocyph\Runwire\Http\Http2\Http2Limits;
use Infocyph\Runwire\Http\Http3\Http3Options;

$server = new Server(
    name: 'web',
    address: '0.0.0.0:8443',
    handler: $handler,
    tls: $tls,
    http1: new Http1Limits(
        adaptive: new AdaptiveProtocolPolicy(
            mode: AdaptivePolicyMode::AUTO,
            lowWatermarkBasisPoints: 1_000,
            highWatermarkBasisPoints: 4_500,
            transitionSamples: 128,
            ewmaNumerator: 1,
            ewmaDenominator: 1,
        ),
    ),
    http2: new Http2Limits(
        adaptive: new AdaptiveProtocolPolicy(
            mode: AdaptivePolicyMode::AUTO,
            lowWatermarkBasisPoints: 1_000,
            highWatermarkBasisPoints: 3_000,
        ),
    ),
    http3: new Http3Options(
        inboundAdaptive: new AdaptiveProtocolPolicy(
            mode: AdaptivePolicyMode::AUTO,
        ),
        outboundAdaptive: new AdaptiveProtocolPolicy(
            mode: AdaptivePolicyMode::AUTO,
            lowWatermarkBasisPoints: 1_000,
            highWatermarkBasisPoints: 4_000,
        ),
    ),
);
```

The values above preserve each protocol's existing tuned crossover settings while changing only the mode. This matters because a standalone `new AdaptiveProtocolPolicy()` defaults to `AUTO` with generic 25%/65% watermarks; replacing a protocol's default policy object with that standalone default is an explicit AUTO opt-in and also replaces that protocol's tuned watermarks.

Policy validation is strict:

```text
0 <= lowWatermarkBasisPoints < highWatermarkBasisPoints <= 10000
transitionSamples >= 1
1 <= ewmaNumerator <= ewmaDenominator <= intdiv(PHP_INT_MAX, 10000)
```

Operational conditions:

| Area | Condition |
| --- | --- |
| H1 connection timing | The selected NODELAY default is applied when a connection is attached; later load changes do not retune an existing connection. |
| H1 AUTO cost | AUTO samples at most eight recent live connections; FIXED/LATENCY/THROUGHPUT bypass AUTO sampling. |
| H1 precedence | Explicit `ListenerOptions(socketContext: ['tcp_nodelay' => ...])` wins over the protocol default. |
| H2 scope | Only the bounded initial-response fast path changes; normal flow control, multiplexing, response queues, and backpressure remain unchanged. |
| H3 inbound | `inboundAdaptive` controls bounded read/poll effort. |
| H3 outbound | `outboundAdaptive` controls bounded response-write effort. |
| Resource ceilings | No adaptive mode raises request/body/header/frame, stream, connection, queue, HPACK/QPACK, WebSocket, or lifecycle ceilings. |

Keep `FIXED` unless your own repeated benchmark evidence shows a reason to select another profile.

## 8. Worker counts

Explicit prefork count:

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

Portable native mode is one process. `workers: 0` and `workers: 1` are valid; explicit `workers > 1` fails startup when prefork capability is unavailable.

## 9. Runtime policies

```php
use Infocyph\Runwire\Runtime\AdmissionPolicy;
use Infocyph\Runwire\Runtime\RequestExecutionPolicy;
use Infocyph\Runwire\RuntimeOptions;
use Infocyph\Runwire\Supervisor\ReloadPolicy;
use Infocyph\Runwire\Supervisor\WorkerRecyclePolicy;

$options = new RuntimeOptions(
    requestExecution: new RequestExecutionPolicy(
        maxExecutionSeconds: 30.0,
    ),
    admission: new AdmissionPolicy(
        maxActiveRequests: 512,
        maxConcurrentConnections: 10_000,
        maxStreamsPerWorker: 2_000,
        retryAfterSeconds: 1,
    ),
    workerRecycle: new WorkerRecyclePolicy(
        maxRequests: 20_000,
        maxLifetimeSeconds: 3_600,
        maxMemoryBytes: 268_435_456,
        jitterRequests: 1_000,
        jitterSeconds: 120,
        gracefulTimeoutSeconds: 15.0,
    ),
    reload: new ReloadPolicy(
        maxUnavailable: 0,
        maxSurge: 1,
        replacementReadyTimeoutSeconds: 15.0,
        drainTimeoutSeconds: 30.0,
    ),
);

Runtime::create($options)
    ->listen(Server::http('0.0.0.0:8080', $handler))
    ->run();
```

Worker recycle/replacement is a prefork capability. Portable mode fails startup when any worker-recycle threshold is enabled because there is no replacement worker. Use an external service manager for whole-process retirement in portable deployments.

## 10. Framed TCP server

```php
<?php

declare(strict_types=1);

use Infocyph\Runwire\Protocol\FramedConnection;
use Infocyph\Runwire\Protocol\LineCodec;
use Infocyph\Runwire\Runtime;
use Infocyph\Runwire\StreamServer;

require __DIR__ . '/vendor/autoload.php';

$server = StreamServer::tcp(
    '127.0.0.1:9000',
    static fn(): LineCodec => new LineCodec("\n", 65_536),
    static function (string $frame, FramedConnection $connection): void {
        $connection->send('echo:' . $frame);
    },
    'line-echo',
);

Runtime::create()->listen($server)->run();
```

Test:

```bash
printf 'hello\n' | nc 127.0.0.1 9000
```

## 11. Unix-domain framed server

```php
$server = StreamServer::unix(
    '/tmp/runwire.sock',
    static fn(): LineCodec => new LineCodec(),
    static function (string $frame, FramedConnection $connection): void {
        $connection->send(strtoupper($frame));
    },
    'unix-example',
);

Runtime::create()->listen($server)->run();
```

Configure permissions/stale-socket/unlink behavior with `UnixListenerOptions` when needed.

## 12. UDP server

```php
<?php

declare(strict_types=1);

use Infocyph\Runwire\DatagramServer;
use Infocyph\Runwire\Network\Datagram;
use Infocyph\Runwire\Network\DatagramListener;
use Infocyph\Runwire\Runtime;

require __DIR__ . '/vendor/autoload.php';

$server = DatagramServer::udp(
    '127.0.0.1:9999',
    static function (Datagram $datagram, DatagramListener $listener): void {
        $listener->sendTo($datagram->payload, $datagram->peerAddress);
    },
    'udp-echo',
);

Runtime::create()->listen($server)->run();
```

UDP provides datagram semantics only; application protocols must account for ordering, duplication, loss, maximum datagram size, and bounded callback work.

## 13. Hosted runtimes and Swoole/OpenSwoole selection

For an already active FPM, FrankenPHP, or RoadRunner host, `RuntimeDriver::AUTO` can resolve the host from the current environment:

```php
<?php

declare(strict_types=1);

use Infocyph\Runwire\Http\HttpRequest;
use Infocyph\Runwire\Http\ResponseWriterInterface;
use Infocyph\Runwire\Runtime;

require __DIR__ . '/vendor/autoload.php';

Runtime::create()->serve(
    static function (HttpRequest $request, ResponseWriterInterface $writer): void {
        $writer->end('served through the active host runtime');
    },
);
```

Installing Swoole/OpenSwoole only makes that driver available; it does not prove that the current CLI process is already hosted by Swoole. Select the Runwire Swoole host adapter explicitly:

```php
<?php

declare(strict_types=1);

use Infocyph\Runwire\Http\HttpRequest;
use Infocyph\Runwire\Http\ResponseWriterInterface;
use Infocyph\Runwire\Runtime;
use Infocyph\Runwire\Runtime\Enum\RuntimeDriver;
use Infocyph\Runwire\RuntimeOptions;
use Infocyph\Runwire\SwooleOptions;

require __DIR__ . '/vendor/autoload.php';

$options = new RuntimeOptions(
    driver: RuntimeDriver::SWOOLE,
    swoole: new SwooleOptions(
        host: '127.0.0.1',
        port: 9501,
        workerCount: 0,
        http2: false,
    ),
);

Runtime::create($options)->serve(
    static function (HttpRequest $request, ResponseWriterInterface $writer): void {
        $writer->end('served through Swoole/OpenSwoole');
    },
);
```

Either compatible `ext-swoole` or `ext-openswoole` can back `RuntimeDriver::SWOOLE`. Explicit selection fails if neither is available.

Use:

```text
Runtime::run()              Runwire-owned native listeners
Runtime::serve()            simple host-owned handler
Runtime::serveApplication() host-owned application factory
```

Do not combine `listen()` with host-owned `serve()`/`serveApplication()`.

## 14. Application factory and lifecycle

```php
<?php

declare(strict_types=1);

use Infocyph\Runwire\Http\Headers;
use Infocyph\Runwire\Http\HttpRequest;
use Infocyph\Runwire\Http\ResponseWriterInterface;
use Infocyph\Runwire\Runtime\ApplicationLifecycleHooks;
use Infocyph\Runwire\Runtime\Host\RuntimeApplication;
use Infocyph\Runwire\Runtime\RuntimeApplicationFactoryInterface;
use Infocyph\Runwire\Runtime\RuntimeApplicationInterface;
use Infocyph\Runwire\RuntimeContext;
use Infocyph\Runwire\Supervisor\Enum\ShutdownReason;

final class AppFactory implements RuntimeApplicationFactoryInterface
{
    public function create(RuntimeContext $context): RuntimeApplicationInterface
    {
        $hooks = new ApplicationLifecycleHooks(
            boot: static function (RuntimeContext $context): void {
                // Open worker-lifetime resources.
            },
            warmup: static function (RuntimeContext $context): void {
                // Validate/warm dependencies before readiness.
            },
            drain: static function (RuntimeContext $context, ShutdownReason $reason): void {
                // Stop starting optional background work.
            },
            shutdown: static function (RuntimeContext $context, ShutdownReason $reason): void {
                // Close worker-lifetime resources.
            },
        );

        return new RuntimeApplication(
            static function (HttpRequest $request, ResponseWriterInterface $writer): void {
                $writer->start(200, Headers::fromArray(['content-type' => 'text/plain']));
                $writer->end('application factory response');
            },
            runtimeContext: $context,
            lifecycle: $hooks,
        );
    }
}
```

Native:

```php
$server = Server::httpApplicationFactory('0.0.0.0:8080', new AppFactory());
Runtime::create()->listen($server)->run();
```

Hosted:

```php
Runtime::create()->serveApplication(new AppFactory());
```

For Swoole/OpenSwoole, construct the runtime with explicit `RuntimeDriver::SWOOLE` options first, then call `serveApplication()`.

Persistent application integrations must reset framework-owned request-local state after each request, including failure/cancellation/deadline paths. See [Runtime Security](security.md).

## 15. Capability checks

```php
use Infocyph\Runwire\Runtime\Enum\RuntimeCapability;

if ($context->supports(RuntimeCapability::PERSISTENT)) {
    // Worker-lifetime state is valid.
}

$context->requireCapability(RuntimeCapability::RUNWIRE_COROUTINES);
```

Prefer capabilities for generic behavior; branch on a driver only for genuinely host-specific APIs.

## 16. Structured coroutines

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

        $right = $scope->spawn(static function () use ($scope): string {
            $scope->yieldNow();
            return 'right';
        });

        return [$left->await(), $right->await()];
    },
);
```

See [Coroutines and structured concurrency](coroutines.md) for channels, futures, synchronization, task-local state, deadlines, request integration, worker background work, and `AsyncConnection`.

## 17. Bounded response transfer

`ResponseTransfer` is intended for already-authorized stream resources and runs inside a coroutine request scope:

```php
use Infocyph\Runwire\Coroutine\CoroutineRuntime;
use Infocyph\Runwire\Coroutine\CoroutineScope;
use Infocyph\Runwire\Http\Headers;
use Infocyph\Runwire\Http\HttpRequest;
use Infocyph\Runwire\Http\ResponseTransfer;
use Infocyph\Runwire\Http\ResponseWriterInterface;
use Infocyph\Runwire\Runtime\CoroutineRequestHandler;

$handler = new CoroutineRequestHandler(
    new CoroutineRuntime(),
    static function (
        HttpRequest $request,
        ResponseWriterInterface $writer,
        CoroutineScope $scope,
    ): void {
        $source = fopen(__DIR__ . '/payload.bin', 'rb');
        if (!is_resource($source)) {
            $writer->start(404);
            $writer->end();

            return;
        }

        $writer->start(200, Headers::fromArray([
            'content-type' => 'application/octet-stream',
        ]));

        ResponseTransfer::stream($scope, $source, $writer);
    },
);
```

The helper does not authorize files or choose response headers. It owns bounded body pumping and closes the source by default.

## 18. Native HTTP/1 WebSocket

```php
use Infocyph\Runwire\Http\HttpRequest;
use Infocyph\Runwire\Http\ResponseWriterInterface;
use Infocyph\Runwire\WebSocket\WebSocketMessage;
use Infocyph\Runwire\WebSocket\WebSocketSession;
use Infocyph\Runwire\WebSocket\WebSocketUpgrade;

$handler = static function (HttpRequest $request, ResponseWriterInterface $writer): void {
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
};
```

This surface is native HTTP/1.1 only in 2.0. Compression and HTTP/2/HTTP/3 WebSocket modes are intentionally not claimed.

## Next reading

- [Architecture and runtime contracts](architecture.md)
- [Deployment and operations](deployment.md)
- [Runtime security and production hardening](security.md)
- [Coroutines and structured concurrency](coroutines.md)
- [Benchmark methodology](benchmarks.md)
