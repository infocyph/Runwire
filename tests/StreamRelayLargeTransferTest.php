<?php

declare(strict_types=1);

use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Network\Connection;
use Infocyph\Runwire\Network\ConnectionLimits;
use Infocyph\Runwire\Network\StreamRelay;

it('preserves a one-megabyte stream through bounded relay queues while the downstream peer stalls', function (): void {
    $upstreamPair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    $downstreamPair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    expect($upstreamPair)->toBeArray()->toHaveCount(2)
        ->and($downstreamPair)->toBeArray()->toHaveCount(2);
    [$upstreamSocket, $upstreamPeer] = $upstreamPair;
    [$downstreamSocket, $downstreamPeer] = $downstreamPair;
    stream_set_blocking($upstreamPeer, false);
    stream_set_blocking($downstreamPeer, false);

    $loop = new SelectLoop();
    $limits = new ConnectionLimits(
        readChunkBytes: 8_192,
        maxReadBytesPerTick: 32_768,
        receiveLowWatermarkBytes: 16_384,
        receiveHighWatermarkBytes: 32_768,
        maxReceiveBufferBytes: 65_536,
        sendLowWatermarkBytes: 16_384,
        sendHighWatermarkBytes: 32_768,
        maxSendBufferBytes: 65_536,
        maxWriteBytesPerTick: 16_384,
    );
    $upstream = new Connection($loop, $upstreamSocket, $limits);
    $downstream = new Connection($loop, $downstreamSocket, $limits);
    $relay = new StreamRelay($loop, $upstream, $downstream, 4_096, 16_384);
    $payload = str_repeat("\x00data\xffsegment\n..", 65_536);
    $expected = hash('sha256', $payload);
    $total = strlen($payload);
    $offset = 0;
    $received = 0;
    $hasher = hash_init('sha256');
    $maxQueued = 0;
    $watchers = [];
    $timer = null;
    $startReading = null;
    $watchdog = $loop->delay(5.0, static function () use ($loop): void {
        $loop->stop();
    });

    try {
        $relay->start();
        $writer = null;
        $writer = $loop->onWritable($upstreamPeer, static function ($stream) use (&$offset, $total, $payload, $loop, &$writer): void {
            if ($offset >= $total) {
                $loop->cancel($writer);

                return;
            }
            $written = fwrite($stream, substr($payload, $offset, 8_192));
            if (is_int($written) && $written > 0) {
                $offset += $written;
            }
            if ($offset === $total) {
                $loop->cancel($writer);
            }
        });
        $watchers[] = $writer;
        $timer = $loop->repeat(0.01, static function () use ($downstream, &$maxQueued): void {
            $maxQueued = max($maxQueued, $downstream->pendingWriteBytes());
        });
        $startReading = $loop->delay(0.08, static function () use ($loop, $downstreamPeer, &$watchers, &$hasher, &$received, $total): void {
            $watchers[] = $loop->onReadable($downstreamPeer, static function ($stream) use ($loop, &$hasher, &$received, $total): void {
                $chunk = fread($stream, 65_536);
                if (is_string($chunk) && $chunk !== '') {
                    hash_update($hasher, $chunk);
                    $received += strlen($chunk);
                }
                if ($received >= $total) {
                    $loop->stop();
                }
            });
        });
        $loop->run();

        expect($offset)->toBe($total)
            ->and($received)->toBe($total)
            ->and(hash_final($hasher))->toBe($expected)
            ->and($relay->transferredBytes()['toRight'])->toBe($total)
            ->and($relay->transferredBytes()['toLeft'])->toBe(0)
            ->and($maxQueued)->toBeGreaterThan(0)
            ->and($maxQueued)->toBeLessThanOrEqual(65_536)
            ->and($downstream->rejectedWrites())->toBe(0);
    } finally {
        $loop->cancel($watchdog);
        if (is_int($startReading)) {
            $loop->cancel($startReading);
        }
        if (is_int($timer)) {
            $loop->cancel($timer);
        }
        foreach ($watchers as $watcher) {
            $loop->cancel($watcher);
        }
        $relay->close();
        fclose($upstreamPeer);
        fclose($downstreamPeer);
    }

    expect($loop->diagnostics()->readWatchers)->toBe(0)
        ->and($loop->diagnostics()->writeWatchers)->toBe(0)
        ->and($loop->diagnostics()->timersActive)->toBe(0);
});
