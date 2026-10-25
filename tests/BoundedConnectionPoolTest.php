<?php

declare(strict_types=1);

use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Network\BoundedConnectionPool;
use Infocyph\Runwire\Network\Connection;
use Infocyph\Runwire\Network\DialEndpoint;
use Infocyph\Runwire\Network\Enum\ConnectionState;
use Infocyph\Runwire\Network\PoolIsolationKey;

it('serializes active leases through a bounded FIFO queue and safely reuses a real idle socket', function (): void {
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    expect($pair)->toBeArray()->toHaveCount(2);
    [$socket, $peer] = $pair;
    $loop = new SelectLoop();
    $connection = new Connection($loop, $socket);
    $pool = new BoundedConnectionPool($loop, maxActive: 1, maxWaiting: 1, maxIdle: 1, idleTtlSeconds: 0.03);
    $key = new PoolIsolationKey(DialEndpoint::parse('unix:///tmp/runwire-active-pool.sock'), 'tenant-one');
    $events = [];
    try {
        $first = $pool->acquire($key, static function (int $id, ?Connection $borrowed, ?Throwable $error) use (&$events): void {
            $events[] = [$id, $borrowed, $error];
        });
        expect($events)->toHaveCount(1)
            ->and($events[0])->toBe([$first, null, null])
            ->and($pool->activeCount())->toBe(1);

        $second = $pool->acquire($key, static function (int $id, ?Connection $borrowed, ?Throwable $error) use (&$events): void {
            $events[] = [$id, $borrowed, $error];
        });
        expect($pool->waitingCount())->toBe(1)
            ->and($events)->toHaveCount(1);
        expect(fn() => $pool->acquire($key, static function (): void {}))->toThrow(OverflowException::class);
        expect($pool->release($first, $connection))->toBeTrue()
            ->and($events)->toHaveCount(2)
            ->and($events[1])->toBe([$second, $connection, null])
            ->and($pool->activeCount())->toBe(1)
            ->and($pool->waitingCount())->toBe(0)
            ->and($pool->idleCount())->toBe(0);

        expect($pool->release($second, $connection))->toBeTrue()
            ->and($pool->idleCount())->toBe(1);
        $stop = $loop->delay(0.08, static function () use ($loop): void {
            $loop->stop();
        });
        $loop->run();
        $loop->cancel($stop);
        expect($pool->idleCount())->toBe(0)
            ->and($connection->state())->toBe(ConnectionState::CLOSED);
    } finally {
        $pool->close();
        $connection->abort();
        fclose($peer);
    }
    expect($loop->diagnostics()->readWatchers)->toBe(0)
        ->and($loop->diagnostics()->writeWatchers)->toBe(0)
        ->and($loop->diagnostics()->timersActive)->toBe(0);
});

it('expires queued leases once while retaining an active lease until the caller releases it', function (): void {
    $loop = new SelectLoop();
    $pool = new BoundedConnectionPool($loop, maxActive: 1, maxWaiting: 2, maxIdle: 1);
    $key = new PoolIsolationKey(DialEndpoint::parse('unix:///tmp/runwire-queued-timeout.sock'), 'worker');
    $errors = [];
    try {
        $active = $pool->acquire($key, static function (): void {});
        $waiting = $pool->acquire(
            $key,
            static function (int $id, ?Connection $socket, ?Throwable $error) use (&$errors): void {
                $errors[] = [$id, $socket, $error];
            },
            waitSeconds: 0.02,
        );
        $stop = $loop->delay(0.05, static function () use ($loop): void {
            $loop->stop();
        });
        $loop->run();
        $loop->cancel($stop);
        expect($errors)->toHaveCount(1)
            ->and($errors[0][0])->toBe($waiting)
            ->and($errors[0][1])->toBeNull()
            ->and($errors[0][2])->toBeInstanceOf(RuntimeException::class)
            ->and($pool->activeCount())->toBe(1)
            ->and($pool->waitingCount())->toBe(0)
            ->and($pool->cancel($waiting))->toBeFalse()
            ->and($pool->release($active))->toBeTrue()
            ->and($pool->activeCount())->toBe(0);
    } finally {
        $pool->close();
    }
    expect($loop->diagnostics()->readWatchers)->toBe(0)
        ->and($loop->diagnostics()->writeWatchers)->toBe(0)
        ->and($loop->diagnostics()->timersActive)->toBe(0);
});

it('rejects stale idle traffic and does not hand a tenant socket to another scope', function (): void {
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    expect($pair)->toBeArray()->toHaveCount(2);
    [$socket, $peer] = $pair;
    $loop = new SelectLoop();
    $connection = new Connection($loop, $socket);
    $pool = new BoundedConnectionPool($loop, maxActive: 1, maxWaiting: 2, maxIdle: 1);
    $endpoint = DialEndpoint::parse('unix:///tmp/runwire-tenant-pool.sock');
    $firstKey = new PoolIsolationKey($endpoint, 'first-tenant');
    $secondKey = new PoolIsolationKey($endpoint, 'second-tenant');
    $borrowed = null;
    try {
        $first = $pool->acquire($firstKey, static function (): void {});
        expect($pool->release($first, $connection))->toBeTrue();
        $second = $pool->acquire($secondKey, static function (int $id, ?Connection $socket) use (&$borrowed): void {
            expect($id)->toBeGreaterThan(0);
            $borrowed = $socket;
        });
        expect($borrowed)->toBeNull()
            ->and($pool->release($second))->toBeTrue();
        fwrite($peer, 'unread-peer-data');
        $stop = $loop->delay(0.02, static function () use ($loop): void {
            $loop->stop();
        });
        $loop->run();
        $loop->cancel($stop);
        expect($pool->idleCount())->toBe(0)
            ->and($connection->state())->toBe(ConnectionState::CLOSED);
    } finally {
        $pool->close();
        $connection->abort();
        fclose($peer);
    }
    expect($loop->diagnostics()->readWatchers)->toBe(0)
        ->and($loop->diagnostics()->writeWatchers)->toBe(0)
        ->and($loop->diagnostics()->timersActive)->toBe(0);
});


it('reports opt-in bounded pool hit, miss, waiter, and retirement counters without exposing tenant identities', function (): void {
    [$socket, $peer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    $loop = new SelectLoop();
    $connection = new Connection($loop, $socket);
    $pool = new BoundedConnectionPool(
        $loop, maxActive: 1, maxWaiting: 1, maxIdle: 1, enableMetrics: true,
    );
    $key = new PoolIsolationKey(DialEndpoint::parse('unix:///tmp/runwire-metric-pool.sock'), 'private-tenant');
    $borrowed = null;
    try {
        $first = $pool->acquire($key, static function (): void {});
        $second = $pool->acquire($key, static function (int $id, ?Connection $conn) use (&$borrowed): void {
            expect($id)->toBeGreaterThan(0);
            $borrowed = $conn;
        });
        expect(fn() => $pool->acquire($key, static function (): void {}))
            ->toThrow(OverflowException::class);
        expect($pool->release($first, $connection))->toBeTrue()
            ->and($borrowed)->toBe($connection);
        expect($pool->release($second, $connection))->toBeTrue();

        $third = $pool->acquire($key, static function (int $id, ?Connection $conn) use (&$borrowed): void {
            expect($id)->toBeGreaterThan(0);
            $borrowed = $conn;
        });
        expect($borrowed)->toBe($connection);
        expect($pool->release($third))->toBeTrue();
        $metrics = $pool->metrics();
        expect($metrics)->toMatchArray([
            'enabled' => true,
            'acquisitions' => 4,
            'reuse_hits' => 2,
            'fresh_dial_slots' => 1,
            'queued' => 1,
            'waiter_grants' => 1,
            'waiter_overflow' => 1,
            'releases' => 3,
            'idle_retained' => 2,
            'active' => 0,
            'waiting' => 0,
            'idle' => 0,
        ])->and(json_encode($metrics, JSON_THROW_ON_ERROR))->not->toContain('private-tenant');
    } finally {
        $pool->close();
        $connection->abort();
        fclose($peer);
    }
    expect($loop->diagnostics()->readWatchers)->toBe(0)
        ->and($loop->diagnostics()->writeWatchers)->toBe(0)
        ->and($loop->diagnostics()->timersActive)->toBe(0);
});

it('keeps pool counters inactive unless explicitly enabled', function (): void {
    $loop = new SelectLoop();
    $pool = new BoundedConnectionPool($loop, maxActive: 1, maxWaiting: 1, maxIdle: 1);
    $key = new PoolIsolationKey(DialEndpoint::parse('unix:///tmp/runwire-off-pool.sock'), 'isolated');
    try {
        $id = $pool->acquire($key, static function (): void {});
        expect($pool->release($id))->toBeTrue();
        expect($pool->metrics())->toMatchArray([
            'enabled' => false, 'acquisitions' => 0,
            'reuse_hits' => 0, 'fresh_dial_slots' => 0, 'releases' => 0,
        ]);
    } finally {
        $pool->close();
    }
});
