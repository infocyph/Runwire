<?php

declare(strict_types=1);

use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Network\Connection;
use Infocyph\Runwire\Network\ConnectionLimits;
use Infocyph\Runwire\Network\StreamRelay;

it('relays two independent streams simultaneously with hash-correct payloads and no retained watchers', function (): void {
    $leftPair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    $rightPair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    \PHPUnit\Framework\Assert::assertIsArray($leftPair);
    \PHPUnit\Framework\Assert::assertIsArray($rightPair);
    \PHPUnit\Framework\Assert::assertCount(2, $leftPair);
    \PHPUnit\Framework\Assert::assertCount(2, $rightPair);
    [$leftSocket, $leftPeer] = $leftPair;
    [$rightSocket, $rightPeer] = $rightPair;
    stream_set_blocking($leftPeer, false);
    stream_set_blocking($rightPeer, false);

    $loop = new SelectLoop();
    $limits = new ConnectionLimits();
    $left = new Connection($loop, $leftSocket, $limits);
    $right = new Connection($loop, $rightSocket, $limits);
    $relay = new StreamRelay($loop, $left, $right, chunkBytes: 1_024, maxBytesPerTick: 4_096);
    $fromLeft = str_repeat('left-to-right-', 2_048);
    $fromRight = str_repeat('right-to-left-', 2_048);
    $received = new class { public string $left = ''; public string $right = ''; };
    $watchers = [];
    $timeout = $loop->delay(1.0, static function () use ($loop): void {
        $loop->stop();
    });

    try {
        $relay->start();
        expect(fwrite($leftPeer, $fromLeft))->toBe(strlen($fromLeft))
            ->and(fwrite($rightPeer, $fromRight))->toBe(strlen($fromRight));
        $watchers[] = $loop->onReadable($leftPeer, static function ($stream) use ($received, $loop, $fromLeft, $fromRight): void {
            $chunk = fread($stream, 65_536);
            if (is_string($chunk)) {
                $received->left .= $chunk;
            }
            if (strlen($received->left) === strlen($fromRight) && strlen($received->right) === strlen($fromLeft)) {
                $loop->stop();
            }
        });
        $watchers[] = $loop->onReadable($rightPeer, static function ($stream) use ($received, $loop, $fromLeft, $fromRight): void {
            $chunk = fread($stream, 65_536);
            if (is_string($chunk)) {
                $received->right .= $chunk;
            }
            if (strlen($received->left) === strlen($fromRight) && strlen($received->right) === strlen($fromLeft)) {
                $loop->stop();
            }
        });
        $loop->run();

        expect(hash('sha256', $received->left))->toBe(hash('sha256', $fromRight))
            ->and(hash('sha256', $received->right))->toBe(hash('sha256', $fromLeft))
            ->and($relay->transferredBytes())->toBe(['toLeft' => strlen($fromRight), 'toRight' => strlen($fromLeft)])
            ->and(fn() => $relay->start())->toThrow(InvalidArgumentException::class);
    } finally {
        foreach ($watchers as $watcher) {
            $loop->cancel($watcher);
        }
        $loop->cancel($timeout);
        $relay->close();
        fclose($leftPeer);
        fclose($rightPeer);
    }

    expect($relay->isClosed())->toBeTrue()
        ->and($loop->diagnostics()->readWatchers)->toBe(0)
        ->and($loop->diagnostics()->writeWatchers)->toBe(0)
        ->and($loop->diagnostics()->timersActive)->toBe(0);
});

it('rejects invalid relay ownership without claiming callbacks', function (): void {
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    \PHPUnit\Framework\Assert::assertIsArray($pair);
    expect($pair)->toHaveCount(2);
    [$socket, $peer] = $pair;
    $loop = new SelectLoop();
    $connection = new Connection($loop, $socket);

    try {
        expect(fn() => new StreamRelay($loop, $connection, $connection))->toThrow(InvalidArgumentException::class)
            ->and(fn() => new StreamRelay($loop, $connection, $connection, 0))->toThrow(InvalidArgumentException::class);
    } finally {
        $connection->abort();
        fclose($peer);
    }
});
