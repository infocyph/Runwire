<?php

declare(strict_types=1);

use Infocyph\Runwire\DatagramServer;
use Infocyph\Runwire\Exception\RuntimeUnavailableException;
use Infocyph\Runwire\Http\HttpRequest;
use Infocyph\Runwire\Http\ResponseWriterInterface;
use Infocyph\Runwire\Network\Datagram;
use Infocyph\Runwire\Network\DatagramListener;
use Infocyph\Runwire\Network\TlsOptions;
use Infocyph\Runwire\Protocol\FramedConnection;
use Infocyph\Runwire\Protocol\LineCodec;
use Infocyph\Runwire\Runtime\Enum\RuntimeDriver;
use Infocyph\Runwire\Runtime\Internal\NativeTopologyValidator;
use Infocyph\Runwire\Runtime\RuntimeCapabilityResolver;
use Infocyph\Runwire\Runtime\RuntimeEnvironment;
use Infocyph\Runwire\Runtime\RuntimeSelection;
use Infocyph\Runwire\RuntimeOptions;
use Infocyph\Runwire\Server;
use Infocyph\Runwire\StreamServer;
use Infocyph\Runwire\Supervisor\WorkerRecyclePolicy;

$nativeSelection = static function (bool $prefork = false, bool $quic = false): RuntimeSelection {
    $environment = new RuntimeEnvironment(
        sapi: 'cli',
        availableDrivers: [RuntimeDriver::NATIVE],
        supportsFork: $prefork,
        supportsSignals: $prefork,
        supportsPosix: $prefork,
        supportsQuic: $quic,
    );

    return new RuntimeSelection(
        RuntimeDriver::NATIVE,
        (new RuntimeCapabilityResolver())->resolve(RuntimeDriver::NATIVE, $environment),
    );
};

it('accepts automatic and single-worker portable native topology', function () use ($nativeSelection): void {
    $http = Server::http(
        '127.0.0.1:8080',
        static function (HttpRequest $request, ResponseWriterInterface $writer): void {
            unset($request, $writer);
        },
        'http-auto',
    )->withWorkers(0);
    $stream = StreamServer::tcp(
        '127.0.0.1:9000',
        static fn(): LineCodec => new LineCodec(),
        static function (string $frame, FramedConnection $connection): void {
            unset($frame, $connection);
        },
        'stream-one',
    )->withWorkers(1);
    $udp = DatagramServer::udp(
        '127.0.0.1:9001',
        static function (Datagram $datagram, DatagramListener $listener): void {
            unset($datagram, $listener);
        },
        'udp-one',
    )->withWorkers(1);

    NativeTopologyValidator::assertSupported(
        [
            $http->name => $http,
            $stream->name => $stream,
            $udp->name => $udp,
        ],
        $nativeSelection(),
        new RuntimeOptions(),
    );

    expect(true)->toBeTrue();
});

it('rejects explicit multi-worker portable native topology for every server kind', function () use ($nativeSelection): void {
    $servers = [
        Server::http(
            '127.0.0.1:8080',
            static function (HttpRequest $request, ResponseWriterInterface $writer): void {
                unset($request, $writer);
            },
            'http-many',
        )->withWorkers(2),
        StreamServer::tcp(
            '127.0.0.1:9000',
            static fn(): LineCodec => new LineCodec(),
            static function (string $frame, FramedConnection $connection): void {
                unset($frame, $connection);
            },
            'stream-many',
        )->withWorkers(2),
        DatagramServer::udp(
            '127.0.0.1:9001',
            static function (Datagram $datagram, DatagramListener $listener): void {
                unset($datagram, $listener);
            },
            'udp-many',
        )->withWorkers(2),
    ];

    foreach ($servers as $server) {
        expect(static fn() => NativeTopologyValidator::assertSupported(
            [$server->name => $server],
            $nativeSelection(),
            new RuntimeOptions(),
        ))->toThrow(
            RuntimeUnavailableException::class,
            'portable native runtime supports only one process',
        );
    }
});

it('rejects enabled worker recycle policy in portable native mode', function () use ($nativeSelection): void {
    $server = Server::http(
        '127.0.0.1:8080',
        static function (HttpRequest $request, ResponseWriterInterface $writer): void {
            unset($request, $writer);
        },
    );
    $options = new RuntimeOptions(
        workerRecycle: new WorkerRecyclePolicy(maxRequests: 100),
    );

    expect(static fn() => NativeTopologyValidator::assertSupported(
        [$server->name => $server],
        $nativeSelection(),
        $options,
    ))->toThrow(
        RuntimeUnavailableException::class,
        'Worker recycle thresholds require native prefork worker-pool capability',
    );
});

it('requires QUIC when native HTTP/3 is explicitly configured', function () use ($nativeSelection): void {
    $certificate = tempnam(sys_get_temp_dir(), 'runwire-cert-');
    $privateKey = tempnam(sys_get_temp_dir(), 'runwire-key-');
    if ($certificate === false || $privateKey === false) {
        throw new RuntimeException('Unable to create temporary TLS files for native topology test.');
    }
    file_put_contents($certificate, 'certificate');
    file_put_contents($privateKey, 'private-key');

    try {
        $server = Server::http(
            '127.0.0.1:8443',
            static function (HttpRequest $request, ResponseWriterInterface $writer): void {
                unset($request, $writer);
            },
        )
            ->withTls(new TlsOptions($certificate, $privateKey))
            ->withHttp3();

        expect(static fn() => NativeTopologyValidator::assertSupported(
            [$server->name => $server],
            $nativeSelection(prefork: true),
            new RuntimeOptions(),
        ))->toThrow(
            RuntimeUnavailableException::class,
            'QUIC capability is unavailable',
        );

        NativeTopologyValidator::assertSupported(
            [$server->name => $server],
            $nativeSelection(quic: true),
            new RuntimeOptions(),
        );
    } finally {
        unlink($certificate);
        unlink($privateKey);
    }
});

it('keeps the prefork HTTP/3 reuse-port requirement', function () use ($nativeSelection): void {
    $certificate = tempnam(sys_get_temp_dir(), 'runwire-cert-');
    $privateKey = tempnam(sys_get_temp_dir(), 'runwire-key-');
    if ($certificate === false || $privateKey === false) {
        throw new RuntimeException('Unable to create temporary TLS files for native topology test.');
    }
    file_put_contents($certificate, 'certificate');
    file_put_contents($privateKey, 'private-key');

    try {
        $server = Server::http(
            '127.0.0.1:8443',
            static function (HttpRequest $request, ResponseWriterInterface $writer): void {
                unset($request, $writer);
            },
        )
            ->withTls(new TlsOptions($certificate, $privateKey))
            ->withHttp3()
            ->withWorkers(2);

        expect(static fn() => NativeTopologyValidator::assertSupported(
            [$server->name => $server],
            $nativeSelection(prefork: true, quic: true),
            new RuntimeOptions(),
        ))->toThrow(
            RuntimeUnavailableException::class,
            'Native HTTP/3 with multiple workers requires explicit ListenerOptions::reusePort support.',
        );
    } finally {
        unlink($certificate);
        unlink($privateKey);
    }
});
