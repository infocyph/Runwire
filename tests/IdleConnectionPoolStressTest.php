<?php

declare(strict_types=1);

use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Network\Connection;
use Infocyph\Runwire\Network\DialEndpoint;
use Infocyph\Runwire\Network\Enum\ConnectionState;
use Infocyph\Runwire\Network\IdleConnectionPool;
use Infocyph\Runwire\Network\PoolIsolationKey;

it('holds a strict four-socket idle cap under repeated real socket admission and recovers all watchers', function (): void {
    $loop = new SelectLoop();
    $pool = new IdleConnectionPool($loop, maxIdle: 4, idleTtlSeconds: 2.0);
    $key = new PoolIsolationKey(DialEndpoint::parse('unix:///tmp/runwire-pool-stress.sock'), 'worker-a');
    $connections = [];
    $peers = [];
    $maxRetained = 0;

    try {
        for ($wave = 0; $wave < 8; ++$wave) {
            for ($j = 0; $j < 12; ++$j) {
                $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
                expect($pair)->toBeArray()->toHaveCount(2);
                [$socket, $peer] = $pair;
                $connection = new Connection($loop, $socket);
                $connections[] = $connection;
                $peers[] = $peer;
                expect($pool->store($key, $connection))->toBeTrue();
                $maxRetained = max($maxRetained, $pool->count());
                expect($pool->count())->toBeLessThanOrEqual(4);
            }
        }

        expect($connections)->toHaveCount(96)
            ->and($pool->count())->toBe(4)
            ->and($maxRetained)->toBe(4);
        foreach (array_slice($connections, 0, -4) as $evicted) {
            expect($evicted->state())->toBe(ConnectionState::CLOSED);
        }
    } finally {
        $pool->close();
        foreach ($connections as $connection) {
            $connection->abort();
        }
        foreach ($peers as $peer) {
            fclose($peer);
        }
    }

    expect($loop->diagnostics()->readWatchers)->toBe(0)
        ->and($loop->diagnostics()->writeWatchers)->toBe(0)
        ->and($loop->diagnostics()->timersActive)->toBe(0);
});

it('refuses to retain a callback-owned stream and never hands it to a different scope', function (): void {
    $loop = new SelectLoop();
    $pool = new IdleConnectionPool($loop);
    $key = new PoolIsolationKey(DialEndpoint::parse('unix:///tmp/runwire-pool-guard.sock'), 'worker-a');
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    expect($pair)->toBeArray()->toHaveCount(2);
    [$socket, $peer] = $pair;
    $connection = new Connection($loop, $socket);

    try {
        $connection->onData(static function (): void {});
        expect($pool->store($key, $connection))->toBeFalse()
            ->and($connection->state())->toBe(ConnectionState::CLOSED)
            ->and($pool->count())->toBe(0)
            ->and($pool->take($key))->toBeNull();
    } finally {
        $pool->close();
        $connection->abort();
        fclose($peer);
    }
});
