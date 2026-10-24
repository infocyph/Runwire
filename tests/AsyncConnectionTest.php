<?php

declare(strict_types=1);

use Infocyph\Runwire\Coroutine\CoroutineRuntime;
use Infocyph\Runwire\Coroutine\CoroutineScope;
use Infocyph\Runwire\Coroutine\Network\AsyncConnection;
use Infocyph\Runwire\Exception\CancelledException;
use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Network\Connection;
use Infocyph\Runwire\Network\ConnectionLimits;
use Infocyph\Runwire\Network\Enum\CloseReason;
use Infocyph\Runwire\Network\Enum\ConnectionState;
use Infocyph\Runwire\Network\Enum\WriteState;
use Infocyph\Runwire\RequestContext;
use Infocyph\Runwire\Runtime\Enum\CancellationReason;

function runwireAsyncConnectionPair(): array
{
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    expect($pair)->toBeArray();

    return $pair;
}

it('awaits connection data and preserves unread bytes across peer EOF', function (): void {
    [$server, $peer] = runwireAsyncConnectionPair();
    stream_set_blocking($peer, false);
    $loop = new SelectLoop();
    $runtime = new CoroutineRuntime($loop);
    $connection = new Connection($loop, $server);

    try {
        $received = $runtime->run(function (CoroutineScope $scope) use ($connection, $loop, $peer): array {
            $connection = new AsyncConnection($connection, $scope);
            $loop->defer(static function (int $id) use ($peer): void {
                unset($id);
                fwrite($peer, 'hello');
                stream_socket_shutdown($peer, STREAM_SHUT_WR);
            });

            return [
                $connection->receive(2),
                $connection->receive(),
                $connection->receive(),
                $connection->closeReason(),
            ];
        });

        expect($received)->toBe(['he', 'llo', '', CloseReason::PEER_CLOSED])
            ->and($connection->state())->toBe(ConnectionState::CLOSED)
            ->and($runtime->activeTaskCount())->toBe(0)
            ->and($loop->diagnostics()->readWatchers)->toBe(0)
            ->and($loop->diagnostics()->writeWatchers)->toBe(0);
    } finally {
        if ($connection->state() !== ConnectionState::CLOSED) {
            $connection->abort();
        }
        if (is_resource($peer)) {
            fclose($peer);
        }
    }
});

it('awaits write-pressure drain without changing the core backpressure metric', function (): void {
    [$server, $peer] = runwireAsyncConnectionPair();
    stream_set_blocking($peer, false);
    $loop = new SelectLoop();
    $runtime = new CoroutineRuntime($loop);
    $limits = new ConnectionLimits(
        readChunkBytes: 1_024,
        maxReadBytesPerTick: 4_096,
        receiveLowWatermarkBytes: 1_024,
        receiveHighWatermarkBytes: 2_048,
        maxReceiveBufferBytes: 4_096,
        sendLowWatermarkBytes: 4_096,
        sendHighWatermarkBytes: 8_192,
        maxSendBufferBytes: 65_536,
        maxWriteBytesPerTick: 4_096,
    );
    $connection = new Connection($loop, $server, $limits);

    try {
        $drainResult = $runtime->run(function (CoroutineScope $scope) use ($connection, $loop, $peer): ?CloseReason {
            $connection = new AsyncConnection($connection, $scope);
            $pressured = false;

            for ($i = 0; $i < 4_096; ++$i) {
                $result = $connection->write(str_repeat('w', 4_096));
                if ($result->state === WriteState::PRESSURED) {
                    $pressured = true;
                    break;
                }
                if ($result->state === WriteState::REJECTED_LIMIT) {
                    break;
                }
            }

            expect($pressured)->toBeTrue();
            $peerWatcher = $loop->onReadable($peer, static function ($stream): void {
                do {
                    $chunk = fread($stream, 65_536);
                } while ($chunk !== '' && $chunk !== false);
            });

            try {
                return $connection->drain();
            } finally {
                $loop->cancel($peerWatcher);
                $connection->abort();
            }
        });

        expect($drainResult)->toBeNull()
            ->and($connection->backpressureEvents())->toBe(1)
            ->and($connection->isWritePressured())->toBeFalse()
            ->and($runtime->activeTaskCount())->toBe(0)
            ->and($loop->diagnostics()->readWatchers)->toBe(0)
            ->and($loop->diagnostics()->writeWatchers)->toBe(0);
    } finally {
        if ($connection->state() !== ConnectionState::CLOSED) {
            $connection->abort();
        }
        if (is_resource($peer)) {
            fclose($peer);
        }
    }
});

it('cancels a pending receive with request cancellation and releases connection ownership', function (): void {
    [$server, $peer] = runwireAsyncConnectionPair();
    $loop = new SelectLoop();
    $runtime = new CoroutineRuntime($loop);
    $connection = new Connection($loop, $server);
    $context = RequestContext::standalone();

    try {
        expect(static fn() => $runtime->runRequest(
            $context,
            function (CoroutineScope $scope) use ($connection, $context, $loop): void {
                $connection = new AsyncConnection($connection, $scope);
                $loop->defer(static function (int $id) use ($context): void {
                    unset($id);
                    $context->cancel(CancellationReason::HOST_CANCELLED);
                });

                try {
                    $connection->receive();
                } finally {
                    $connection->abort();
                }
            },
        ))->toThrow(CancelledException::class);

        expect($connection->state())->toBe(ConnectionState::CLOSED)
            ->and($connection->closeReason())->toBe(CloseReason::LOCAL_ABORT)
            ->and($runtime->activeTaskCount())->toBe(0)
            ->and($loop->diagnostics()->readWatchers)->toBe(0)
            ->and($loop->diagnostics()->writeWatchers)->toBe(0);
    } finally {
        if ($connection->state() !== ConnectionState::CLOSED) {
            $connection->abort();
        }
        fclose($peer);
    }
});

it('prevents callback-core consumers from overwriting adapter-owned callback slots', function (): void {
    [$server, $peer] = runwireAsyncConnectionPair();
    $loop = new SelectLoop();
    $runtime = new CoroutineRuntime($loop);
    $connection = new Connection($loop, $server);

    try {
        $runtime->run(function (CoroutineScope $scope) use ($connection): void {
            $async = new AsyncConnection($connection, $scope);

            try {
                expect(static fn() => $connection->onData(static function (): void {}))
                    ->toThrow(LogicException::class, 'Connection callback slots are exclusively owned by an adapter.');
            } finally {
                $async->abort();
            }
        });

        expect($connection->state())->toBe(ConnectionState::CLOSED)
            ->and($runtime->activeTaskCount())->toBe(0);
    } finally {
        if ($connection->state() !== ConnectionState::CLOSED) {
            $connection->abort();
        }
        fclose($peer);
    }
});

it('detaches callback ownership without closing the underlying connection', function (): void {
    [$server, $peer] = runwireAsyncConnectionPair();
    $loop = new SelectLoop();
    $runtime = new CoroutineRuntime($loop);
    $connection = new Connection($loop, $server);

    try {
        $runtime->run(function (CoroutineScope $scope) use ($connection, $loop): void {
            $async = new AsyncConnection($connection, $scope);
            $async->dispose();

            expect(static fn() => $async->receive())
                ->toThrow(LogicException::class, 'Async connection adapter is disposed.');

            $connection->onData(static function (): void {});
            $loop->stop();
        });

        expect($connection->state())->toBe(ConnectionState::OPEN);
    } finally {
        if ($connection->state() !== ConnectionState::CLOSED) {
            $connection->abort();
        }
        fclose($peer);
    }
});

it('completes graceful close synchronously through the coroutine adapter', function (): void {
    [$server, $peer] = runwireAsyncConnectionPair();
    $loop = new SelectLoop();
    $runtime = new CoroutineRuntime($loop);
    $connection = new Connection($loop, $server);

    try {
        $reason = $runtime->run(function (CoroutineScope $scope) use ($connection): CloseReason {
            return (new AsyncConnection($connection, $scope))->close();
        });

        expect($reason)->toBe(CloseReason::LOCAL_GRACEFUL)
            ->and($connection->state())->toBe(ConnectionState::CLOSED)
            ->and($loop->diagnostics()->readWatchers)->toBe(0)
            ->and($loop->diagnostics()->writeWatchers)->toBe(0);
    } finally {
        if ($connection->state() !== ConnectionState::CLOSED) {
            $connection->abort();
        }
        fclose($peer);
    }
});


it('rejects a pending coroutine receive on unverified TLS EOF instead of returning empty data', function (): void {
    [$stream, $peer] = runwireAsyncConnectionPair();
    $loop = new SelectLoop();
    $runtime = new CoroutineRuntime($loop);
    $core = new Connection($loop, $stream, encrypted: true, strictTlsPeerEof: true);

    try {
        expect(fn() => $runtime->run(function (CoroutineScope $scope) use ($core, $loop, $peer): string {
            $async = new AsyncConnection($core, $scope);
            $loop->defer(static function (int $id) use ($peer): void {
                unset($id);
                fclose($peer);
            });

            return $async->receive();
        }))->toThrow(RuntimeException::class, 'TLS peer EOF was not authenticated by close_notify.');

        expect($core->closeReason())->toBe(CloseReason::TLS_UNVERIFIED_EOF)
            ->and($loop->diagnostics()->readWatchers)->toBe(0)
            ->and($loop->diagnostics()->writeWatchers)->toBe(0);
    } finally {
        $core->abort();
        if (is_resource($peer)) {
            fclose($peer);
        }
    }
});

it('delivers observed TLS bytes but refuses to represent truncated ciphertext as clean EOF', function (): void {
    [$stream, $peer] = runwireAsyncConnectionPair();
    $loop = new SelectLoop();
    $runtime = new CoroutineRuntime($loop);
    $core = new Connection($loop, $stream, encrypted: true, strictTlsPeerEof: true);

    try {
        $reason = $runtime->run(function (CoroutineScope $scope) use ($core, $loop, $peer): ?CloseReason {
            $async = new AsyncConnection($core, $scope);
            $loop->defer(static function (int $id) use ($peer): void {
                unset($id);
                fwrite($peer, 'fragment');
                fclose($peer);
            });

            expect($async->receive())->toBe('fragment');
            expect(fn() => $async->receive())->toThrow(
                RuntimeException::class,
                'TLS peer EOF was not authenticated by close_notify.',
            );

            return $async->closeReason();
        });

        expect($reason)->toBe(CloseReason::TLS_UNVERIFIED_EOF)
            ->and($loop->diagnostics()->readWatchers)->toBe(0)
            ->and($loop->diagnostics()->writeWatchers)->toBe(0);
    } finally {
        $core->abort();
        if (is_resource($peer)) {
            fclose($peer);
        }
    }
});


it('releases scope-owned established connections on normal coroutine completion', function (): void {
    [$stream, $peer] = runwireAsyncConnectionPair();
    $loop = new SelectLoop();
    $core = new Connection($loop, $stream);
    $runtime = new CoroutineRuntime($loop);

    try {
        $adapter = $runtime->run(static function (CoroutineScope $scope) use ($core): AsyncConnection {
            return new AsyncConnection($core, $scope);
        });
        expect($adapter)->toBeInstanceOf(AsyncConnection::class)
            ->and($core->state())->toBe(ConnectionState::CLOSED)
            ->and($core->closeReason())->toBe(CloseReason::LOCAL_ABORT)
            ->and($runtime->activeTaskCount())->toBe(0)
            ->and($loop->diagnostics()->readWatchers)->toBe(0)
            ->and($loop->diagnostics()->writeWatchers)->toBe(0);
    } finally {
        $core->abort();
        fclose($peer);
    }
});

it('hands off a coroutine connection to a pool token without retaining its request owner', function (): void {
    [$stream, $peer] = runwireAsyncConnectionPair();
    $loop = new SelectLoop();
    $runtime = new CoroutineRuntime($loop);
    $core = new Connection($loop, $stream);
    $pool = new \Infocyph\Runwire\CancellationSource();

    try {
        $handedOff = $runtime->run(static function (CoroutineScope $scope) use ($core, $pool, $loop): Connection {
            $async = new AsyncConnection($core, $scope);
            $connection = $async->transferTo($pool->token());
            expect(fn() => $async->receive())->toThrow(LogicException::class, 'disposed');
            $loop->stop();

            return $connection;
        });
        expect($handedOff)->toBe($core)
            ->and($core->state())->toBe(ConnectionState::OPEN)
            ->and($pool->token()->subscriptionCount())->toBe(1);

        $received = '';
        $handedOff->onData(static function (Connection $connection) use (&$received): void {
            $received .= $connection->read();
        });
        fwrite($peer, 'pooled-payload');
        $loop->delay(0.02, static function () use ($pool): void {
            $pool->cancel(CancellationReason::HOST_CANCELLED);
        });
        $loop->run();

        expect($received)->toBe('pooled-payload')
            ->and($core->state())->toBe(ConnectionState::CLOSED)
            ->and($pool->token()->subscriptionCount())->toBe(0)
            ->and($loop->diagnostics()->readWatchers)->toBe(0)
            ->and($loop->diagnostics()->timersActive)->toBe(0);
    } finally {
        $core->abort();
        $pool->dispose();
        fclose($peer);
    }
});


it('rejects a same-scope handoff without detaching the original stream lifetime', function (): void {
    [$stream, $peer] = runwireAsyncConnectionPair();
    $loop = new SelectLoop();
    $runtime = new CoroutineRuntime($loop);
    $core = new Connection($loop, $stream);

    try {
        $runtime->run(static function (CoroutineScope $scope) use ($core): void {
            $async = new AsyncConnection($core, $scope);
            expect(fn() => $async->transferTo($scope->cancellation()))->toThrow(
                LogicException::class,
                'different owner token',
            );
            expect($core->state())->toBe(ConnectionState::OPEN);
            $async->abort();
        });

        expect($core->state())->toBe(ConnectionState::CLOSED)
            ->and($loop->diagnostics()->readWatchers)->toBe(0);
    } finally {
        $core->abort();
        fclose($peer);
    }
});


it('releases socket watchers, timers and coroutine scopes through repeated live request turns', function (): void {
    $loop = new SelectLoop();
    $runtime = new CoroutineRuntime($loop);
    $responses = [];
    $baselineBytes = memory_get_usage(true);
    $fdBaseline = is_dir('/proc/self/fd') ? count(scandir('/proc/self/fd') ?: []) : null;

    for ($iteration = 0; $iteration < 48; ++$iteration) {
        [$server, $peer] = runwireAsyncConnectionPair();
        $connection = new Connection($loop, $server);
        try {
            $payload = 'tick-' . $iteration;
            $loop->defer(static function (int $id) use ($peer, $payload): void {
                unset($id);
                fwrite($peer, $payload);
            });

            $responses[] = $runtime->run(static function (CoroutineScope $scope) use ($connection, $payload): string {
                $task = $scope->spawn(static function () use ($scope): void {
                    $scope->sleep(0.0005);
                });
                $adapter = new AsyncConnection($connection, $scope);
                $data = '';
                while (strlen($data) < strlen($payload)) {
                    $chunk = $adapter->receive(strlen($payload) - strlen($data));
                    if ($chunk === '') {
                        throw new RuntimeException('Mixed-I/O response ended before the expected payload.');
                    }
                    $data .= $chunk;
                }
                $task->await();

                return $data;
            });

            expect($connection->closeReason())->toBe(CloseReason::LOCAL_ABORT)
                ->and($runtime->activeTaskCount())->toBe(0)
                ->and($loop->diagnostics()->readWatchers)->toBe(0)
                ->and($loop->diagnostics()->writeWatchers)->toBe(0)
                ->and($loop->diagnostics()->timersActive)->toBe(0)
                ->and($loop->diagnostics()->deferredBacklog)->toBe(0);
        } finally {
            $connection->abort();
            fclose($peer);
        }
    }

    expect($responses)->toHaveCount(48);
    foreach ($responses as $iteration => $data) {
        expect($data)->toBe('tick-' . $iteration);
    }
    gc_collect_cycles();
    expect(memory_get_usage(true))->toBeLessThanOrEqual($baselineBytes + 16 * 1_048_576)
        ->and($loop->diagnostics()->maxLagNanoseconds)->toBeLessThan(1_000_000_000);

    if ($fdBaseline !== null) {
        expect(count(scandir('/proc/self/fd') ?: []))->toBeLessThanOrEqual($fdBaseline + 4);
    }
});


it('does not retain the coroutine close observer after transferring to a pool', function (): void {
    [$stream, $peer] = runwireAsyncConnectionPair();
    $loop = new SelectLoop();
    $runtime = new CoroutineRuntime($loop);
    $core = new Connection($loop, $stream);
    $pool = new \Infocyph\Runwire\CancellationSource();
    $observers = new ReflectionProperty($core, 'closeCallbacks');

    try {
        $connection = $runtime->run(static function (CoroutineScope $scope) use ($core, $pool, $loop): Connection {
            $adapter = new AsyncConnection($core, $scope);
            expect($adapter->transferTo($pool->token()))->toBe($core);
            $loop->stop();

            return $core;
        });

        expect($connection)->toBe($core)
            ->and($connection->state())->toBe(ConnectionState::OPEN)
            ->and($observers->getValue($connection))->toBe([])
            ->and($pool->token()->subscriptionCount())->toBe(1);

        $pool->cancel(CancellationReason::HOST_CANCELLED);
        expect($connection->closeReason())->toBe(CloseReason::LOCAL_ABORT)
            ->and($observers->getValue($connection))->toBe([])
            ->and($loop->diagnostics()->readWatchers)->toBe(0);
    } finally {
        $core->abort();
        $pool->dispose();
        fclose($peer);
    }
});


it('adopts and re-pools the same connection through twelve independent request scopes', function (): void {
    [$stream, $peer] = runwireAsyncConnectionPair();
    $loop = new SelectLoop();
    $runtime = new CoroutineRuntime($loop);
    $connection = new Connection($loop, $stream);
    $pool = new \Infocyph\Runwire\CancellationSource();
    $connection->bindOwner($pool->token());
    $observers = new ReflectionProperty($connection, 'closeCallbacks');

    try {
        for ($round = 0; $round < 12; ++$round) {
            $payload = sprintf('m%04d', $round);
            fwrite($peer, $payload);

            $received = $runtime->run(static function (CoroutineScope $scope) use ($connection, $pool, $loop): string {
                $adapter = AsyncConnection::adopt($connection, $scope);
                expect($pool->token()->subscriptionCount())->toBe(0);
                $data = '';
                while (strlen($data) < 5) {
                    $fragment = $adapter->receive(5 - strlen($data));
                    if ($fragment === '') {
                        throw new RuntimeException('Pooled socket returned EOF before the response.');
                    }
                    $data .= $fragment;
                }
                $adapter->transferTo($pool->token());
                $loop->stop();

                return $data;
            });

            expect($received)->toBe($payload)
                ->and($connection->state())->toBe(ConnectionState::OPEN)
                ->and($pool->token()->subscriptionCount())->toBe(1)
                ->and($observers->getValue($connection))->toBe([])
                ->and($runtime->activeTaskCount())->toBe(0);
        }

        $pool->cancel(CancellationReason::HOST_CANCELLED);
        expect($connection->closeReason())->toBe(CloseReason::LOCAL_ABORT)
            ->and($pool->token()->subscriptionCount())->toBe(0)
            ->and($loop->diagnostics()->readWatchers)->toBe(0)
            ->and($loop->diagnostics()->writeWatchers)->toBe(0)
            ->and($loop->diagnostics()->timersActive)->toBe(0);
    } finally {
        $connection->abort();
        $pool->dispose();
        fclose($peer);
    }
});


it('aborts a pooled connection when coroutine adoption cannot claim its callback slots', function (): void {
    [$stream, $peer] = runwireAsyncConnectionPair();
    $loop = new SelectLoop();
    $runtime = new CoroutineRuntime($loop);
    $core = new Connection($loop, $stream);
    $pool = new \Infocyph\Runwire\CancellationSource();

    try {
        $core->bindOwner($pool->token());
        $core->onData(static function (): void {});
        expect(fn() => $runtime->run(
            static fn(CoroutineScope $scope) => AsyncConnection::adopt($core, $scope),
        ))->toThrow(LogicException::class, 'already configured');

        expect($core->closeReason())->toBe(CloseReason::LOCAL_ABORT)
            ->and($pool->token()->subscriptionCount())->toBe(0)
            ->and($runtime->activeTaskCount())->toBe(0)
            ->and($loop->diagnostics()->readWatchers)->toBe(0)
            ->and($loop->diagnostics()->timersActive)->toBe(0);
    } finally {
        $core->abort();
        $pool->dispose();
        fclose($peer);
    }
});


it('preserves scope ownership after disposing only a coroutine socket adapter', function (): void {
    [$stream, $peer] = runwireAsyncConnectionPair();
    $loop = new SelectLoop();
    $connection = new Connection($loop, $stream);
    $runtime = new CoroutineRuntime($loop);
    $closeObservers = new ReflectionProperty($connection, 'closeCallbacks');

    try {
        $runtime->run(static function (CoroutineScope $scope) use ($connection, $closeObservers): void {
            $adapter = new AsyncConnection($connection, $scope);
            $adapter->dispose();

            expect($connection->state())->toBe(ConnectionState::OPEN)
                ->and($closeObservers->getValue($connection))->toBe([])
                ->and(fn() => $adapter->receive())->toThrow(LogicException::class, 'disposed');
        });

        expect($connection->state())->toBe(ConnectionState::CLOSED)
            ->and($connection->closeReason())->toBe(CloseReason::LOCAL_ABORT)
            ->and($runtime->activeTaskCount())->toBe(0)
            ->and($loop->diagnostics()->readWatchers)->toBe(0)
            ->and($loop->diagnostics()->writeWatchers)->toBe(0)
            ->and($loop->diagnostics()->timersActive)->toBe(0);
    } finally {
        $connection->abort();
        fclose($peer);
    }
});
