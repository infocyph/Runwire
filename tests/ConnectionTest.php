<?php

declare(strict_types=1);

use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Network\CloseReason;
use Infocyph\Runwire\Network\Connection;
use Infocyph\Runwire\Network\ConnectionLimits;
use Infocyph\Runwire\Network\ConnectionState;
use Infocyph\Runwire\Network\WriteState;

function runwireConnectionPair(): array
{
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    expect($pair)->toBeArray();
    return $pair;
}

it('bounds receive buffering and resumes below the low watermark', function (): void {
    [$server, $peer] = runwireConnectionPair();
    $loop = new SelectLoop();
    $limits = new ConnectionLimits(
        readChunkBytes: 1_024,
        maxReadBytesPerTick: 8_192,
        receiveLowWatermarkBytes: 2_048,
        receiveHighWatermarkBytes: 4_096,
        maxReceiveBufferBytes: 8_192,
        sendLowWatermarkBytes: 2_048,
        sendHighWatermarkBytes: 4_096,
        maxSendBufferBytes: 8_192,
        maxWriteBytesPerTick: 4_096,
    );
    $connection = new Connection($loop, $server, $limits);

    fwrite($peer, str_repeat('r', 8_192));
    $loop->delay(0.02, static fn () => $loop->stop());
    $loop->run();

    expect($connection->receivedBytes())->toBe(4_096)
        ->and($connection->isReadPressurePaused())->toBeTrue()
        ->and(strlen($connection->read(3_072)))->toBe(3_072)
        ->and($connection->isReadPressurePaused())->toBeFalse();

    $connection->abort();
    fclose($peer);
});

it('bounds the send queue and signals drain after pressure clears', function (): void {
    [$server, $peer] = runwireConnectionPair();
    stream_set_blocking($peer, false);
    $loop = new SelectLoop();
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

    expect($pressured)->toBeTrue()
        ->and($connection->pendingWriteBytes())->toBeLessThanOrEqual($limits->maxSendBufferBytes)
        ->and($connection->write(str_repeat('x', $limits->maxSendBufferBytes + 1))->state)->toBe(WriteState::REJECTED_LIMIT);

    $drained = false;
    $connection->onDrain(static function () use (&$drained, $loop): void {
        $drained = true;
        $loop->stop();
    });
    $loop->onReadable($peer, static function ($stream): void {
        do {
            $chunk = fread($stream, 65_536);
        } while ($chunk !== '' && $chunk !== false);
    });
    $loop->delay(1.0, static fn () => $loop->stop());
    $loop->run();

    expect($drained)->toBeTrue()->and($connection->isWritePressured())->toBeFalse();
    $connection->abort();
    fclose($peer);
});

it('delivers final bytes before peer EOF closes the connection', function (): void {
    [$server, $peer] = runwireConnectionPair();
    $loop = new SelectLoop();
    $connection = new Connection($loop, $server);
    $payload = '';
    $connection->onData(static function (Connection $connection) use (&$payload): void {
        $payload .= $connection->read();
    });

    fwrite($peer, 'final-bytes');
    stream_socket_shutdown($peer, STREAM_SHUT_WR);
    $loop->delay(0.2, static fn () => $loop->stop());
    $loop->run();

    expect($payload)->toBe('final-bytes')
        ->and($connection->state())->toBe(ConnectionState::CLOSED)
        ->and($connection->closeReason())->toBe(CloseReason::PEER_CLOSED);
    fclose($peer);
});

it('flushes a synchronous EOF response before closing', function (): void {
    [$server, $peer] = runwireConnectionPair();
    $loop = new SelectLoop();
    $connection = new Connection($loop, $server);
    $connection->onEof(static function (Connection $connection): void { $connection->write('bye'); });

    stream_socket_shutdown($peer, STREAM_SHUT_WR);
    $loop->delay(1.0, static fn () => $loop->stop());
    $loop->run();

    expect(stream_get_contents($peer))->toBe('bye')->and($connection->closeReason())->toBe(CloseReason::PEER_CLOSED);
    fclose($peer);
});

it('uses activity-aware idle deadlines without rescheduling on every I/O', function (): void {
    [$server, $peer] = runwireConnectionPair();
    $loop = new SelectLoop();
    $connection = new Connection($loop, $server, new ConnectionLimits(idleTimeoutSeconds: 0.06));
    $connection->onData(static fn (Connection $connection): string => $connection->read());

    $loop->delay(0.04, static function () use ($peer): void { fwrite($peer, 'keepalive'); });
    $loop->delay(0.075, static function () use ($connection, $loop): void {
        expect($connection->state())->toBe(ConnectionState::OPEN);
        $loop->stop();
    });
    $loop->run();

    $connection->abort();
    fclose($peer);
});
