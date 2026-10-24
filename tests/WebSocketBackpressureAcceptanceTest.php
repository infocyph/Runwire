<?php

declare(strict_types=1);

use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Network\Connection;
use Infocyph\Runwire\Network\ConnectionLimits;
use Infocyph\Runwire\WebSocket\WebSocketOptions;
use Infocyph\Runwire\WebSocket\WebSocketSession;

it('bounds outgoing WebSocket messages behind a stalled peer and resumes under drain pressure', function (): void {
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    expect($pair)->toBeArray()->toHaveCount(2);
    [$socket, $peer] = $pair;
    stream_set_blocking($peer, false);

    $loop = new SelectLoop();
    $connection = new Connection($loop, $socket, new ConnectionLimits(
        sendLowWatermarkBytes: 1_024,
        sendHighWatermarkBytes: 2_048,
        maxSendBufferBytes: 4_096,
        maxWriteBytesPerTick: 1_024,
    ));
    $session = new WebSocketSession($loop, $connection, new WebSocketOptions(
        maxFramePayloadBytes: 2_048,
        maxMessageBytes: 4_096,
        maxBufferedBytes: 8_192,
        heartbeatIntervalSeconds: 1.0,
        idleTimeoutSeconds: 2.0,
    ));
    $pressured = false;
    $rejected = false;
    $drained = false;
    $wire = '';
    $watcher = null;
    $stop = null;

    try {
        for ($i = 0; $i < 8_192; ++$i) {
            $result = $session->sendText(str_repeat('x', 512));
            $pressured = $pressured || $result->pressured();
            if (!$result->accepted()) {
                $rejected = true;
                break;
            }
        }

        expect($pressured)->toBeTrue()
            ->and($rejected)->toBeTrue()
            ->and($connection->pendingWriteBytes())->toBeLessThanOrEqual(4_096);

        $session->onDrain(static function (WebSocketSession $owner) use (&$drained): void {
            $drained = !$owner->isClosed();
        });
        $watcher = $loop->onReadable($peer, static function ($stream) use (&$wire): void {
            while (($part = fread($stream, 65_536)) !== false && $part !== '') {
                $wire .= $part;
            }
        });
        $stop = $loop->delay(0.2, static function () use ($loop): void {
            $loop->stop();
        });
        $loop->run();

        expect($drained)->toBeTrue()
            ->and(strlen($wire))->toBeGreaterThan(0)
            ->and($connection->pendingWriteBytes())->toBeLessThanOrEqual(4_096);
    } finally {
        if (is_int($watcher)) {
            $loop->cancel($watcher);
        }
        if (is_int($stop)) {
            $loop->cancel($stop);
        }
        $connection->abort();
        fclose($peer);
    }

    expect($loop->diagnostics()->readWatchers)->toBe(0)
        ->and($loop->diagnostics()->writeWatchers)->toBe(0)
        ->and($loop->diagnostics()->timersActive)->toBe(0);
});
