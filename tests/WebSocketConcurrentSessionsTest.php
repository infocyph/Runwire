<?php

declare(strict_types=1);

use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Network\Connection;
use Infocyph\Runwire\Network\ConnectionLimits;
use Infocyph\Runwire\WebSocket\Internal\WebSocketWireCodec;
use Infocyph\Runwire\WebSocket\WebSocketMessage;
use Infocyph\Runwire\WebSocket\WebSocketOptions;
use Infocyph\Runwire\WebSocket\WebSocketSession;

it('keeps 128 simultaneous fragmented WebSocket streams and heartbeat owners isolated', function (): void {
    $loop = new SelectLoop();
    $connections = [];
    $peers = [];
    $sessions = [];
    $seen = [];
    $delivered = 0;
    $count = 128;
    $options = new WebSocketOptions(
        maxFramePayloadBytes: 256,
        maxMessageBytes: 512,
        maxBufferedBytes: 1_024,
        heartbeatIntervalSeconds: 1.0,
        idleTimeoutSeconds: 2.0,
    );
    $watchdog = $loop->delay(1.0, static function () use ($loop): void {
        $loop->stop();
    });

    try {
        for ($i = 0; $i < $count; ++$i) {
            $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
            expect($pair)->toBeArray()->toHaveCount(2);
            [$socket, $peer] = $pair;
            $peers[] = $peer;
            stream_set_blocking($peer, false);
            $connection = new Connection($loop, $socket, new ConnectionLimits());
            $connections[] = $connection;
            $session = new WebSocketSession($loop, $connection, $options);
            $sessions[] = $session;
            $session->onMessage(static function (WebSocketSession $owner, WebSocketMessage $message) use (
                &$seen,
                &$delivered,
                $loop,
                $count,
            ): void {
                $seen[$message->data] = !$owner->isClosed() && !$message->binary;
                ++$delivered;
                if ($delivered === $count) {
                    $loop->stop();
                }
            });

            $wire = WebSocketWireCodec::clientFrame(0x1, 'client-' . $i, false)
                . WebSocketWireCodec::clientFrame(0x9, 'heartbeat')
                . WebSocketWireCodec::clientFrame(0x0, '-complete');
            expect(fwrite($peer, $wire))->toBe(strlen($wire));
        }

        $loop->run();
        expect($delivered)->toBe($count)
            ->and($seen)->toHaveCount($count);
        foreach ($seen as $healthy) {
            expect($healthy)->toBeTrue();
        }
        // Every independent socket sent a ping between fragmented frames.
        // Verify that all 128 independent peers received its own pong.
        foreach ($peers as $peer) {
            $wire = stream_get_contents($peer);
            expect($wire)->toBeString()
                ->toContain(WebSocketWireCodec::frame(0xA, 'heartbeat'));
        }
    } finally {
        $loop->cancel($watchdog);
        foreach ($connections as $connection) {
            $connection->abort();
        }
        foreach ($peers as $peer) {
            fclose($peer);
        }
        unset($sessions);
    }

    expect($loop->diagnostics()->readWatchers)->toBe(0)
        ->and($loop->diagnostics()->writeWatchers)->toBe(0)
        ->and($loop->diagnostics()->timersActive)->toBe(0);
});
