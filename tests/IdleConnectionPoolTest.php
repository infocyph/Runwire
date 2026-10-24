<?php

declare(strict_types=1);

use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Network\Connection;
use Infocyph\Runwire\Network\DialEndpoint;
use Infocyph\Runwire\Network\Enum\ConnectionState;
use Infocyph\Runwire\Network\IdleConnectionPool;
use Infocyph\Runwire\Network\PoolIsolationKey;

it('never reuses a stream under a different tenant scope and closes overflow victims', function (): void {
    $loop = new SelectLoop();
    $pool = new IdleConnectionPool($loop, maxIdle: 1, idleTtlSeconds: 1.0);
    $origin = DialEndpoint::parse('unix:///tmp/runwire-pool-a.sock');
    $a = new PoolIsolationKey($origin, 'worker-a');
    $b = new PoolIsolationKey($origin, 'worker-b');
    $first = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    $second = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    expect($first)->toBeArray()->toHaveCount(2)
        ->and($second)->toBeArray()->toHaveCount(2);
    [$sockA, $peerA] = $first;
    [$sockB, $peerB] = $second;
    $firstConnection = new Connection($loop, $sockA);
    $secondConnection = new Connection($loop, $sockB);

    try {
        expect($pool->store($a, $firstConnection))->toBeTrue()
            ->and($pool->count())->toBe(1)
            ->and($pool->take($b))->toBeNull()
            ->and($pool->count())->toBe(1);
        expect($pool->store($b, $secondConnection))->toBeTrue()
            ->and($firstConnection->state())->toBe(ConnectionState::CLOSED)
            ->and($pool->count())->toBe(1)
            ->and($pool->take($a))->toBeNull()
            ->and($pool->take($b))->toBe($secondConnection)
            ->and($pool->count())->toBe(0)
            ->and($secondConnection->isPoolIdleSafe())->toBeTrue();
    } finally {
        $pool->close();
        $firstConnection->abort();
        $secondConnection->abort();
        fclose($peerA);
        fclose($peerB);
    }

    expect($loop->diagnostics()->readWatchers)->toBe(0)
        ->and($loop->diagnostics()->writeWatchers)->toBe(0)
        ->and($loop->diagnostics()->timersActive)->toBe(0);
});

it('refuses to retain a plaintext socket under a TLS and ALPN identity', function (): void {
    $loop = new SelectLoop();
    $pool = new IdleConnectionPool($loop, 1);
    $key = new PoolIsolationKey(
        DialEndpoint::parse('tcp://127.0.0.1:443'),
        'worker-a',
        new \Infocyph\Runwire\Network\TlsClientOptions('localhost', alpnProtocols: ['http/1.1']),
        tlsPolicyIdentity: 'trust-revision-1',
        negotiatedAlpn: 'http/1.1',
    );
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    expect($pair)->toBeArray()->toHaveCount(2);
    [$socket, $peer] = $pair;
    $connection = new Connection($loop, $socket);

    try {
        expect($key->acceptsTransport($connection))->toBeFalse()
            ->and($pool->store($key, $connection))->toBeFalse()
            ->and($connection->state())->toBe(ConnectionState::CLOSED)
            ->and($pool->count())->toBe(0);
    } finally {
        $pool->close();
        $connection->abort();
        fclose($peer);
    }
});

it('evicts idle sockets on unsolicited response bytes or deadline without stale reuse', function (): void {
    $loop = new SelectLoop();
    $pool = new IdleConnectionPool($loop, maxIdle: 2, idleTtlSeconds: 0.03);
    $key = new PoolIsolationKey(DialEndpoint::parse('unix:///tmp/runwire-pool-b.sock'), 'worker-a');
    $first = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    $second = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    expect($first)->toBeArray()->toHaveCount(2)
        ->and($second)->toBeArray()->toHaveCount(2);
    [$sockA, $peerA] = $first;
    [$sockB, $peerB] = $second;
    $firstConnection = new Connection($loop, $sockA);
    $secondConnection = new Connection($loop, $sockB);
    $watchdog = $loop->delay(0.08, static function () use ($loop): void {
        $loop->stop();
    });

    try {
        expect($pool->store($key, $firstConnection))->toBeTrue();
        fwrite($peerA, 'unsolicited-next-response');
        $loop->run();
        expect($pool->count())->toBe(0)
            ->and($firstConnection->state())->toBe(ConnectionState::CLOSED)
            ->and($pool->take($key))->toBeNull();
        expect($pool->store($key, $secondConnection))->toBeTrue();
        $loop->delay(0.045, static function () use ($loop): void {
            $loop->stop();
        });
        $loop->run();
        expect($secondConnection->state())->toBe(ConnectionState::CLOSED)
            ->and($pool->take($key))->toBeNull();
    } finally {
        $loop->cancel($watchdog);
        $pool->close();
        $firstConnection->abort();
        $secondConnection->abort();
        fclose($peerA);
        fclose($peerB);
    }

    expect($loop->diagnostics()->readWatchers)->toBe(0)
        ->and($loop->diagnostics()->writeWatchers)->toBe(0)
        ->and($loop->diagnostics()->timersActive)->toBe(0);
});
