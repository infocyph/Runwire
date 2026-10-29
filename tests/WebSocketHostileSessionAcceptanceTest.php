<?php

declare(strict_types=1);

use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Network\Connection;
use Infocyph\Runwire\Network\Internal\ByteBudget;
use Infocyph\Runwire\WebSocket\Internal\WebSocketWireCodec;
use Infocyph\Runwire\WebSocket\WebSocketMessage;
use Infocyph\Runwire\WebSocket\WebSocketOptions;
use Infocyph\Runwire\WebSocket\WebSocketSession;

it('isolates simultaneous fragmented, invalid-control, oversized and cancelled WebSocket sessions', function (): void {
    $loop = new SelectLoop();
    $options = new WebSocketOptions(
        maxFramePayloadBytes: 64,
        maxMessageBytes: 128,
        maxBufferedBytes: 256,
        heartbeatIntervalSeconds: 1.0,
        idleTimeoutSeconds: 2.0,
        closeTimeoutSeconds: 0.1,
    );
    $sockets = [];
    $peers = [];
    $sessions = [];
    $budgets = [];
    $closed = [];
    $delivered = [];
    $count = 64;
    $stop = null;

    try {
        for ($index = 0; $index < $count; ++$index) {
            [$socket, $peer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
            stream_set_blocking($peer, false);
            $budget = new ByteBudget(256);
            $connection = new Connection($loop, $socket, bufferBudget: $budget);
            $session = new WebSocketSession($loop, $connection, $options);
            $sockets[] = $connection;
            $peers[] = $peer;
            $sessions[] = $session;
            $budgets[] = $budget;
            $closed[$index] = 0;

            $session->onMessage(static function (WebSocketSession $owner, WebSocketMessage $message) use (&$delivered, $index): void {
                $delivered[$index] = !$owner->isClosed() && !$message->binary && $message->data === 'hello-' . $index;
            });
            $session->onClose(static function () use (&$closed, $index): void {
                ++$closed[$index];
            });

            $mode = $index % 4;
            $wire = match ($mode) {
                0 => WebSocketWireCodec::clientFrame(0x1, 'hello-', false)
                    . WebSocketWireCodec::clientFrame(0x9, 'hb')
                    . WebSocketWireCodec::clientFrame(0x0, (string) $index),
                1 => "\x09\x80\x01\x02\x03\x04",
                2 => WebSocketWireCodec::clientFrame(0x2, str_repeat('a', 64), false)
                    . WebSocketWireCodec::clientFrame(0x0, str_repeat('b', 64), false)
                    . WebSocketWireCodec::clientFrame(0x0, 'c'),
                default => WebSocketWireCodec::clientFrame(0x1, str_repeat('z', 32), false),
            };
            expect(fwrite($peer, $wire))->toBe(strlen($wire));
        }

        $stop = $loop->delay(0.14, static function () use ($loop): void {
            $loop->stop();
        });
        $loop->run();

        expect($delivered)->toHaveCount(16);

        foreach ($sessions as $index => $session) {
            $mode = $index % 4;
            $response = stream_get_contents($peers[$index]);
            if ($mode === 0) {
                expect($delivered[$index])->toBeTrue()
                    ->and($response)->toContain(WebSocketWireCodec::frame(0xA, 'hb'));
            } elseif ($mode === 1) {
                expect($response)->toContain(WebSocketWireCodec::frame(0x8, pack('n', 1002) . 'WebSocket control frames must be final and at most 125 bytes.'));
            } elseif ($mode === 2) {
                expect($response)->toContain(WebSocketWireCodec::frame(0x8, pack('n', 1009) . 'Fragmented WebSocket message exceeds the configured ceiling.'));
            } else {
                expect($delivered)->not->toHaveKey($index);
            }
            expect($budgets[$index]->used())->toBeLessThanOrEqual(256);
            $sockets[$index]->abort();
            expect($session->isClosed())->toBeTrue()
                ->and($closed[$index])->toBe(1)
                ->and($budgets[$index]->used())->toBe(0);
        }
    } finally {
        if ($stop !== null) {
            $loop->cancel($stop);
        }
        foreach ($sockets as $socket) {
            $socket->abort();
        }
        foreach ($peers as $peer) {
            fclose($peer);
        }
    }

    expect($loop->diagnostics()->readWatchers)->toBe(0)
        ->and($loop->diagnostics()->writeWatchers)->toBe(0)
        ->and($loop->diagnostics()->timersActive)->toBe(0);
});
