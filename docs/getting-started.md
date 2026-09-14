# Runwire 1.0 Getting Started

This guide takes a new consumer from installation to the main Runwire 1.0 serving modes using public APIs and complete examples.

## Install

Runwire requires 64-bit PHP `^8.4`.

```bash
composer require infocyph/runwire
```

Optional capabilities:

```text
ext-pcntl + ext-posix   native prefork supervision/reload/recycle
ext-openssl             TLS and HTTP/2 ALPN
ext-quic                native QUIC / HTTP/3
ext-swoole              Swoole host integration
ext-openswoole          OpenSwoole host integration
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

HTTP/3 uses UDP/QUIC while the TCP listener continues to serve HTTP/1.1/HTTP/2. 0-RTT application dispatch is disabled in Runwire 1.0. Explicit HTTP/3 configuration without supported QUIC capability is a startup error; it is never silently ignored.

## 7. Worker counts

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

## 8. Runtime policies

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

## 9. Framed TCP server

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

## 10. Unix-domain framed server

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

## 11. UDP server

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

## 12. Hosted runtimes

For FPM, FrankenPHP, RoadRunner, Swoole, and OpenSwoole, the host owns serving:

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

Use:

```text
Runtime::run()              Runwire-owned native listeners
Runtime::serve()            simple host-owned handler
Runtime::serveApplication() host-owned application factory
```

## 13. Application factory and lifecycle

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

Persistent application integrations must reset framework-owned request-local state after each request, including failure/cancellation/deadline paths. See [Runtime Security](security.md).

## 14. Capability checks

```php
use Infocyph\Runwire\Runtime\Enum\RuntimeCapability;

if ($context->supports(RuntimeCapability::PERSISTENT)) {
    // Worker-lifetime state is valid.
}

$context->requireCapability(RuntimeCapability::RUNWIRE_COROUTINES);
```

Prefer capabilities for generic behavior; branch on a driver only for genuinely host-specific APIs.

## 15. Structured coroutines

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

## Next reading

- [Architecture and runtime contracts](architecture.md)
- [Deployment and operations](deployment.md)
- [Runtime security and production hardening](security.md)
- [Coroutines and structured concurrency](coroutines.md)
- [Benchmark methodology](benchmarks.md)
- [Runwire 1.0 launch plan](plans/runwire-1.0-foundation-3-launch-plan.md)
