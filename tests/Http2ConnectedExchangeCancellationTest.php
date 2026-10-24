<?php

declare(strict_types=1);

use Infocyph\Runwire\CancellationSource;
use Infocyph\Runwire\Exception\CancelledException;
use Infocyph\Runwire\Http\Client\ClientRequest;
use Infocyph\Runwire\Http\Client\Http2ConnectedExchange;
use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Network\Connection;
use Infocyph\Runwire\RequestDeadline;
use Infocyph\Runwire\Runtime\Enum\CancellationReason;

it('reclaims an idle H2 request socket and cancellation observer on explicit host cancellation', function (): void {
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    expect($pair)->toBeArray()->toHaveCount(2);
    [$outbound, $silentPeer] = $pair;
    $loop = new SelectLoop();
    $source = new CancellationSource();
    $connection = new Connection($loop, $outbound, negotiatedProtocol: 'h2', encrypted: true);
    $calls = 0;
    $error = null;
    $exchange = new Http2ConnectedExchange(
        $loop,
        $connection,
        new ClientRequest('GET', 'https://localhost/wait', cancellation: $source->token()),
        static function (): void {},
        static function (): void {},
        static function (?Connection $returned, ?Throwable $failure) use (&$calls, &$error, $loop): void {
            expect($returned)->toBeNull();
            ++$calls;
            $error = $failure;
            $loop->stop();
        },
    );
    $cancel = $loop->delay(0.02, static function () use ($source): void {
        $source->cancel(CancellationReason::HOST_CANCELLED);
    });
    try {
        $exchange->start();
        $loop->run();
        $exchange->close();
        expect($calls)->toBe(1)
            ->and($error)->toBeInstanceOf(CancelledException::class)
            ->and($source->token()->subscriptionCount())->toBe(0)
            ->and($connection->isPoolIdleSafe())->toBeFalse();
    } finally {
        $loop->cancel($cancel);
        $exchange->close();
        $connection->abort();
        $source->dispose();
        fclose($silentPeer);
    }
    expect($loop->diagnostics()->readWatchers)->toBe(0)
        ->and($loop->diagnostics()->writeWatchers)->toBe(0)
        ->and($loop->diagnostics()->timersActive)->toBe(0);
});

it('enforces a monotonic response deadline against a silent H2 peer without orphaned watchers', function (): void {
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    expect($pair)->toBeArray()->toHaveCount(2);
    [$outbound, $silentPeer] = $pair;
    $loop = new SelectLoop();
    $connection = new Connection($loop, $outbound, negotiatedProtocol: 'h2', encrypted: true);
    $calls = 0;
    $error = null;
    $exchange = new Http2ConnectedExchange(
        $loop,
        $connection,
        new ClientRequest(
            'GET',
            'https://localhost/silent',
            deadline: RequestDeadline::afterSeconds(0.03, hrtime(true)),
        ),
        static function (): void {},
        static function (): void {},
        static function (?Connection $returned, ?Throwable $failure) use (&$calls, &$error, $loop): void {
            expect($returned)->toBeNull();
            ++$calls;
            $error = $failure;
            $loop->stop();
        },
    );
    try {
        $exchange->start();
        $loop->run();
        expect($calls)->toBe(1)
            ->and($error)->toBeInstanceOf(RuntimeException::class)
            ->and($error->getMessage())->toContain('deadline expired');
    } finally {
        $exchange->close();
        $connection->abort();
        fclose($silentPeer);
    }
    expect($loop->diagnostics()->readWatchers)->toBe(0)
        ->and($loop->diagnostics()->writeWatchers)->toBe(0)
        ->and($loop->diagnostics()->timersActive)->toBe(0);
});
