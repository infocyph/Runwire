<?php

declare(strict_types=1);

use Infocyph\Runwire\DatagramServer;
use Infocyph\Runwire\Exception\RuntimeUnavailableException;
use Infocyph\Runwire\Http\HttpRequest;
use Infocyph\Runwire\Http\ResponseWriterInterface;
use Infocyph\Runwire\Network\Datagram;
use Infocyph\Runwire\Network\DatagramListener;
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

$nativeSelection = static function (bool $prefork = false): RuntimeSelection {
    $environment = new RuntimeEnvironment(
        sapi: 'cli',
        availableDrivers: [RuntimeDriver::NATIVE],
        supportsFork: $prefork,
        supportsSignals: $prefork,
        supportsPosix: $prefork,
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

    expect([$http->workers, $stream->workers, $udp->workers])->toBe([0, 1, 1]);
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
