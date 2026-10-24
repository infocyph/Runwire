<?php

declare(strict_types=1);

use Infocyph\Runwire\CancellationSource;
use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Network\Connection;
use Infocyph\Runwire\Network\StreamRelay;
use Infocyph\Runwire\RequestDeadline;
use Infocyph\Runwire\Runtime\Enum\CancellationReason;

it('cancels both relay directions exactly once without leaked cancellation subscriptions', function (): void {
    $leftPair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    $rightPair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    expect($leftPair)->toBeArray()->toHaveCount(2)
        ->and($rightPair)->toBeArray()->toHaveCount(2);
    [$leftSocket, $leftPeer] = $leftPair;
    [$rightSocket, $rightPeer] = $rightPair;
    $loop = new SelectLoop();
    $source = new CancellationSource();
    $left = new Connection($loop, $leftSocket);
    $right = new Connection($loop, $rightSocket);
    $relay = new StreamRelay($loop, $left, $right, cancellation: $source->token());

    try {
        $relay->start();
        expect($source->token()->subscriptionCount())->toBe(1)
            ->and($relay->isClosed())->toBeFalse();
        $source->cancel(CancellationReason::HOST_CANCELLED);
        $relay->close();
        expect($relay->isClosed())->toBeTrue()
            ->and($source->token()->subscriptionCount())->toBe(0)
            ->and($loop->diagnostics()->readWatchers)->toBe(0)
            ->and($loop->diagnostics()->writeWatchers)->toBe(0)
            ->and($loop->diagnostics()->timersActive)->toBe(0);
    } finally {
        $source->dispose();
        $relay->close();
        fclose($leftPeer);
        fclose($rightPeer);
    }
});

it('aborts a relay on its monotonic deadline without leaving reactor timers', function (): void {
    $leftPair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    $rightPair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    expect($leftPair)->toBeArray()->toHaveCount(2)
        ->and($rightPair)->toBeArray()->toHaveCount(2);
    [$leftSocket, $leftPeer] = $leftPair;
    [$rightSocket, $rightPeer] = $rightPair;
    $loop = new SelectLoop();
    $left = new Connection($loop, $leftSocket);
    $right = new Connection($loop, $rightSocket);
    $relay = new StreamRelay(
        $loop,
        $left,
        $right,
        deadline: RequestDeadline::afterSeconds(0.03, hrtime(true)),
    );
    $right->onClose(static function () use ($loop): void {
        $loop->stop();
    });
    $watchdog = $loop->delay(0.5, static function () use ($loop): void {
        $loop->stop();
    });

    try {
        $relay->start();
        $loop->run();
        expect($relay->isClosed())->toBeTrue();
    } finally {
        $relay->close();
        $loop->cancel($watchdog);
        fclose($leftPeer);
        fclose($rightPeer);
    }

    expect($loop->diagnostics()->readWatchers)->toBe(0)
        ->and($loop->diagnostics()->writeWatchers)->toBe(0)
        ->and($loop->diagnostics()->timersActive)->toBe(0);
});
