<?php

declare(strict_types=1);

use Infocyph\Runwire\Control\ControlOptions;
use Infocyph\Runwire\DatagramServer;
use Infocyph\Runwire\Exception\RuntimeUnavailableException;
use Infocyph\Runwire\Http\Http3\Http3Options;
use Infocyph\Runwire\Http\HttpRequest;
use Infocyph\Runwire\Http\ResponseWriterInterface;
use Infocyph\Runwire\Network\Datagram;
use Infocyph\Runwire\Network\DatagramListener;
use Infocyph\Runwire\Network\TcpListener;
use Infocyph\Runwire\Network\TlsOptions;
use Infocyph\Runwire\Network\UnixListener;
use Infocyph\Runwire\Protocol\FramedConnection;
use Infocyph\Runwire\Protocol\LineCodec;
use Infocyph\Runwire\Runtime;
use Infocyph\Runwire\Runtime\DevelopmentWatchPolicy;
use Infocyph\Runwire\Runtime\Enum\RuntimeDriver;
use Infocyph\Runwire\Runtime\Internal\BoundDatagramServer;
use Infocyph\Runwire\Runtime\Internal\BoundServer;
use Infocyph\Runwire\Runtime\Internal\BoundStreamServer;
use Infocyph\Runwire\Runtime\Internal\NativeTopologyValidator;
use Infocyph\Runwire\Runtime\Internal\PortableNativeRuntime;
use Infocyph\Runwire\Runtime\RuntimeCapabilityResolver;
use Infocyph\Runwire\Runtime\RuntimeEnvironmentProbe;
use Infocyph\Runwire\Runtime\RuntimeSelection;
use Infocyph\Runwire\RuntimeOptions;
use Infocyph\Runwire\Server;
use Infocyph\Runwire\StreamServer;
use Infocyph\Runwire\Supervisor\PrivilegeDropPolicy;
use Infocyph\Runwire\Supervisor\WorkerRecyclePolicy;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$fail = static function (string $message): never {
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
};
$assert = static function (bool $condition, string $message) use ($fail): void {
    if (!$condition) {
        $fail($message);
    }
};
$expectUnavailable = static function (callable $operation, string $needle) use ($fail): void {
    try {
        $operation();
    } catch (RuntimeUnavailableException $error) {
        if (!str_contains($error->getMessage(), $needle)) {
            $fail(sprintf('Unexpected portable rejection: %s', $error->getMessage()));
        }

        return;
    }

    $fail(sprintf('Expected portable rejection containing "%s".', $needle));
};

$environment = (new RuntimeEnvironmentProbe())->probe();
$assert(!function_exists('pcntl_fork'), 'PCNTL must be genuinely unavailable in portable acceptance.');
$assert(!$environment->supportsFork, 'Environment incorrectly reports fork support.');

$capabilities = (new RuntimeCapabilityResolver())->resolve(RuntimeDriver::NATIVE, $environment);
$selection = new RuntimeSelection(RuntimeDriver::NATIVE, $capabilities);
$assert(!$capabilities->ownsWorkerPool, 'Portable native must not own a worker pool.');
$assert(!$capabilities->supportsGracefulReload, 'Portable native must not advertise graceful reload.');
$assert(!$capabilities->supportsWorkerRecycle, 'Portable native must not advertise worker recycling.');
$assert(!$capabilities->supportsFork, 'Portable native capabilities must report supportsFork=false.');

$noopHttp = static function (HttpRequest $request, ResponseWriterInterface $writer): void {
    unset($request);
    $writer->end('noop');
};

NativeTopologyValidator::assertSupported(
    ['http' => Server::http('127.0.0.1:0', $noopHttp, 'http')->withWorkers(0)],
    $selection,
    new RuntimeOptions(driver: RuntimeDriver::NATIVE),
);
NativeTopologyValidator::assertSupported(
    ['http' => Server::http('127.0.0.1:0', $noopHttp, 'http')->withWorkers(1)],
    $selection,
    new RuntimeOptions(driver: RuntimeDriver::NATIVE),
);

$expectUnavailable(
    static fn() => NativeTopologyValidator::assertSupported(
        ['http' => Server::http('127.0.0.1:0', $noopHttp, 'many')->withWorkers(2)],
        $selection,
        new RuntimeOptions(driver: RuntimeDriver::NATIVE),
    ),
    'supports only one process',
);
$expectUnavailable(
    static fn() => NativeTopologyValidator::assertSupported(
        ['http' => Server::http('127.0.0.1:0', $noopHttp, 'recycle')],
        $selection,
        new RuntimeOptions(
            driver: RuntimeDriver::NATIVE,
            workerRecycle: new WorkerRecyclePolicy(maxRequests: 10),
        ),
    ),
    'Worker recycle thresholds require',
);

$certificate = tempnam(sys_get_temp_dir(), 'runwire-portable-cert-');
if ($certificate === false) {
    $fail('Unable to allocate temporary TLS certificate path.');
}
file_put_contents($certificate, "placeholder\n");
try {
    $http3 = new Server(
        name: 'http3',
        address: '127.0.0.1:0',
        handler: $noopHttp,
        tls: new TlsOptions($certificate),
        http3: new Http3Options(),
    );
    if (!$capabilities->supportsQuic) {
        $expectUnavailable(
            static fn() => NativeTopologyValidator::assertSupported(
                ['http3' => $http3],
                $selection,
                new RuntimeOptions(driver: RuntimeDriver::NATIVE),
            ),
            'QUIC capability is unavailable',
        );
    }
} finally {
    @unlink($certificate);
}

$runtimeFailure = static function (callable $configure, string $needle, ?RuntimeOptions $options = null) use (
    $expectUnavailable,
    $noopHttp,
): void {
    $runtime = Runtime::create($options ?? new RuntimeOptions(driver: RuntimeDriver::NATIVE));
    $runtime->listen(Server::http('127.0.0.1:0', $noopHttp, 'guard'));
    $configure($runtime);
    $expectUnavailable(static fn() => $runtime->run(), $needle);
};

$runtimeFailure(
    static fn(Runtime $runtime) => $runtime->control(new ControlOptions(sys_get_temp_dir() . '/runwire-portable-control.sock')),
    'control endpoint requires',
);
$runtimeFailure(
    static fn(Runtime $runtime) => $runtime->watch(new DevelopmentWatchPolicy(enabled: true, paths: [__DIR__])),
    'Development worker watching requires',
);
$runtimeFailure(
    static fn(Runtime $runtime) => $runtime->onEvent(static function (): void {}),
    'Supervisor lifecycle events require',
);
$runtimeFailure(
    static fn(Runtime $runtime) => $runtime,
    'Worker UID/GID changes were requested',
    new RuntimeOptions(
        driver: RuntimeDriver::NATIVE,
        privilegeDrop: new PrivilegeDropPolicy(uid: 1),
    ),
);

$portable = null;
$handled = 0;
$bound = [];
$clients = [];
$unixPath = sys_get_temp_dir() . '/runwire-portable-' . getmypid() . '.sock';

$expected = 3;
$includeUnix = $capabilities->supportsUnixSockets;
if ($includeUnix) {
    ++$expected;
}
$markHandled = static function () use (&$handled, $expected, &$portable): void {
    ++$handled;
    if ($handled === $expected) {
        $portable?->stop();
    }
};

$http = Server::http(
    '127.0.0.1:0',
    static function (HttpRequest $request, ResponseWriterInterface $writer) use ($markHandled): void {
        if ($request->target !== '/portable') {
            throw new RuntimeException('Unexpected portable HTTP target.');
        }
        $writer->end('portable-http-ok');
        $markHandled();
    },
    'http',
);
$httpListener = TcpListener::bind($http->address, $http->listener, $http->connection, $http->tls);
$bound['http'] = new BoundServer($http, $httpListener);

$tcp = StreamServer::tcp(
    '127.0.0.1:0',
    static fn(): LineCodec => new LineCodec(),
    static function (string $frame, FramedConnection $connection) use ($markHandled): void {
        if ($frame !== 'portable-tcp') {
            throw new RuntimeException('Unexpected portable TCP frame.');
        }
        $connection->send('portable-tcp-ok');
        $connection->closeGracefully();
        $markHandled();
    },
    'tcp',
);
$tcpListener = TcpListener::bind($tcp->address, $tcp->listener, $tcp->connection, $tcp->tls);
$bound['tcp'] = new BoundStreamServer($tcp, $tcpListener);

$udp = DatagramServer::udp(
    '127.0.0.1:0',
    static function (Datagram $datagram, DatagramListener $listener) use ($markHandled): void {
        if ($datagram->payload !== 'portable-udp') {
            throw new RuntimeException('Unexpected portable UDP datagram.');
        }
        $listener->sendTo('portable-udp-ok', $datagram->peerAddress);
        $markHandled();
    },
    'udp',
);
$udpListener = DatagramListener::bind($udp->address, $udp->options);
$bound['udp'] = new BoundDatagramServer($udp, $udpListener);

$unixListener = null;
if ($includeUnix) {
    $unix = StreamServer::unix(
        $unixPath,
        static fn(): LineCodec => new LineCodec(),
        static function (string $frame, FramedConnection $connection) use ($markHandled): void {
            if ($frame !== 'portable-unix') {
                throw new RuntimeException('Unexpected portable Unix frame.');
            }
            $connection->send('portable-unix-ok');
            $connection->closeGracefully();
            $markHandled();
        },
        'unix',
    );
    $unixListener = UnixListener::bind($unix->address, $unix->unix, $unix->connection);
    $bound['unix'] = new BoundStreamServer($unix, $unixListener);
}

$connect = static function (string $uri) use ($fail) {
    $errno = 0;
    $error = '';
    $client = stream_socket_client($uri, $errno, $error, 2.0);
    if (!is_resource($client)) {
        $fail(sprintf('Unable to connect portable acceptance client %s: %s (%d).', $uri, $error, $errno));
    }
    stream_set_timeout($client, 2);

    return $client;
};

try {
    $clients['http'] = $connect('tcp://' . $httpListener->address());
    fwrite($clients['http'], "GET /portable HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n");

    $clients['tcp'] = $connect('tcp://' . $tcpListener->address());
    fwrite($clients['tcp'], "portable-tcp\n");

    $clients['udp'] = $connect('udp://' . $udpListener->address());
    fwrite($clients['udp'], 'portable-udp');

    if ($includeUnix) {
        $clients['unix'] = $connect('unix://' . $unixPath);
        fwrite($clients['unix'], "portable-unix\n");
    }

    $portable = new PortableNativeRuntime(
        $bound,
        $selection,
        new RuntimeOptions(driver: RuntimeDriver::NATIVE),
    );
    $portable->run();

    $httpResponse = stream_get_contents($clients['http']);
    $assert(is_string($httpResponse) && str_contains($httpResponse, 'HTTP/1.1 200'), 'Portable HTTP response status missing.');
    $assert(str_contains($httpResponse, 'portable-http-ok'), 'Portable HTTP response body missing.');

    $tcpResponse = fgets($clients['tcp']);
    $assert(trim((string) $tcpResponse) === 'portable-tcp-ok', 'Portable framed TCP response mismatch.');

    $udpResponse = stream_socket_recvfrom($clients['udp'], 1024);
    $assert($udpResponse === 'portable-udp-ok', 'Portable UDP response mismatch.');

    if ($includeUnix) {
        $unixResponse = fgets($clients['unix']);
        $assert(trim((string) $unixResponse) === 'portable-unix-ok', 'Portable Unix response mismatch.');
    }

    $assert($handled === $expected, 'Portable runtime did not handle every expected transport.');
} finally {
    foreach ($clients as $client) {
        if (is_resource($client)) {
            fclose($client);
        }
    }
    $httpListener->close();
    $tcpListener->close();
    $udpListener->close();
    if ($unixListener !== null) {
        $unixListener->close(true);
    }
    @unlink($unixPath);
}

echo "portable-native-ok\n";
