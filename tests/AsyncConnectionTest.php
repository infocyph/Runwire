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
            $connection = new AsyncConnection($scope, $connection);
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
            $connection = new AsyncConnection($scope, $connection);
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
                $connection = new AsyncConnection($scope, $connection);
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
            $async = new AsyncConnection($scope, $connection);

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
            $async = new AsyncConnection($scope, $connection);
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
            return (new AsyncConnection($scope, $connection))->close();
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
