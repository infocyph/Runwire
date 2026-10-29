<?php

declare(strict_types=1);

use Infocyph\Runwire\CancellationSource;
use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Network\Connection;
use Infocyph\Runwire\Network\StreamRelay;
use Infocyph\Runwire\Runtime\Enum\CancellationReason;

it('preserves a full duplex reply after one peer sends FIN and drains the opposite peer', function (): void {
    $leftPair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    $rightPair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    expect($leftPair)->toBeArray()->toHaveCount(2)
        ->and($rightPair)->toBeArray()->toHaveCount(2);
    [$leftSocket, $leftPeer] = $leftPair;
    [$rightSocket, $rightPeer] = $rightPair;
    stream_set_blocking($leftPeer, false);
    stream_set_blocking($rightPeer, false);

    $loop = new SelectLoop();
    $left = new Connection($loop, $leftSocket);
    $right = new Connection($loop, $rightSocket);
    $relay = new StreamRelay($loop, $left, $right);
    $request = '';
    $reply = '';
    $answered = false;
    $finished = false;
    $rightWatcher = $loop->onReadable($rightPeer, static function ($stream) use (&$request, &$answered): void {
        $chunk = fread($stream, 8_192);
        if (is_string($chunk)) {
            $request .= $chunk;
        }
        if (!$answered && feof($stream) && $request === 'half-close-request') {
            $answered = true;
            expect(fwrite($stream, 'response-after-fin'))->toBe(strlen('response-after-fin'));
            expect(stream_socket_shutdown($stream, STREAM_SHUT_WR))->toBeTrue();
        }
    });
    $leftWatcher = $loop->onReadable($leftPeer, static function ($stream) use (&$reply, &$finished, $loop): void {
        $chunk = fread($stream, 8_192);
        if (is_string($chunk)) {
            $reply .= $chunk;
        }
        if (feof($stream)) {
            $finished = true;
            $loop->stop();
        }
    });
    $watchdog = $loop->delay(2.0, static function () use ($loop): void {
        $loop->stop();
    });

    try {
        $relay->start();
        expect(fwrite($leftPeer, 'half-close-request'))->toBe(strlen('half-close-request'));
        expect(stream_socket_shutdown($leftPeer, STREAM_SHUT_WR))->toBeTrue();
        $loop->run();
        expect($finished)->toBeTrue()
            ->and($answered)->toBeTrue()
            ->and($request)->toBe('half-close-request')
            ->and($reply)->toBe('response-after-fin')
            ->and($relay->isClosed())->toBeTrue()
            ->and($relay->transferredBytes())->toBe([
                'toLeft' => strlen('response-after-fin'),
                'toRight' => strlen('half-close-request'),
            ]);
    } finally {
        $loop->cancel($watchdog);
        $loop->cancel($leftWatcher);
        $loop->cancel($rightWatcher);
        $relay->close();
        fclose($leftPeer);
        fclose($rightPeer);
    }

    expect($loop->diagnostics()->readWatchers)->toBe(0)
        ->and($loop->diagnostics()->writeWatchers)->toBe(0)
        ->and($loop->diagnostics()->timersActive)->toBe(0);
});

it('interrupts a partially half-closed relay without orphaning watchers or cancellation owners', function (): void {
    $leftPair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    $rightPair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    expect($leftPair)->toBeArray()->toHaveCount(2)
        ->and($rightPair)->toBeArray()->toHaveCount(2);
    [$leftSocket, $leftPeer] = $leftPair;
    [$rightSocket, $rightPeer] = $rightPair;
    $loop = new SelectLoop();
    $source = new CancellationSource();
    $relay = new StreamRelay(
        $loop,
        new Connection($loop, $leftSocket),
        new Connection($loop, $rightSocket),
        cancellation: $source->token(),
    );
    try {
        $relay->start();
        expect(fwrite($leftPeer, 'fin'))->toBe(3);
        expect(stream_socket_shutdown($leftPeer, STREAM_SHUT_WR))->toBeTrue();
        $timer = $loop->delay(0.02, static function () use ($source, $loop): void {
            $source->cancel(CancellationReason::WORKER_SHUTDOWN);
            $loop->stop();
        });
        $loop->run();
        $loop->cancel($timer);
        expect($relay->isClosed())->toBeTrue()
            ->and($source->token()->subscriptionCount())->toBe(0);
    } finally {
        $source->dispose();
        $relay->close();
        fclose($leftPeer);
        fclose($rightPeer);
    }
    expect($loop->diagnostics()->readWatchers)->toBe(0)
        ->and($loop->diagnostics()->writeWatchers)->toBe(0)
        ->and($loop->diagnostics()->timersActive)->toBe(0);
});
