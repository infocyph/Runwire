<?php

declare(strict_types=1);

use Infocyph\Runwire\CancellationSource;
use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Network\Connection;
use Infocyph\Runwire\Runtime\Enum\CancellationReason;

it('refuses pool reuse of streams owned by callbacks scopes or close observers', function (): void {
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    expect($pair)->toBeArray()->toHaveCount(2);
    [$socket, $peer] = $pair;
    $loop = new SelectLoop();
    $connection = new Connection($loop, $socket);
    $source = new CancellationSource();

    try {
        expect($connection->isPoolIdleSafe())->toBeTrue();
        $observer = $connection->registerCloseObserver(static function (): void {});
        expect($observer)->toBeInt()
            ->and($connection->isPoolIdleSafe())->toBeFalse();
        $connection->unregisterCloseObserver($observer);
        expect($connection->isPoolIdleSafe())->toBeTrue();

        $owner = new stdClass();
        $connection->claimCallbacks(
            $owner,
            static function (): void {},
            static function (): void {},
            static function (): void {},
        );
        expect($connection->isPoolIdleSafe())->toBeFalse();
        $connection->releaseCallbacks($owner);
        expect($connection->isPoolIdleSafe())->toBeTrue();

        $connection->bindOwner($source->token());
        expect($connection->isPoolIdleSafe())->toBeFalse();
        $source->cancel(CancellationReason::HOST_CANCELLED);
        expect($connection->isPoolIdleSafe())->toBeFalse()
            ->and($source->token()->subscriptionCount())->toBe(0);
    } finally {
        $connection->abort();
        $source->dispose();
        fclose($peer);
    }

    expect($loop->diagnostics()->readWatchers)->toBe(0)
        ->and($loop->diagnostics()->writeWatchers)->toBe(0)
        ->and($loop->diagnostics()->timersActive)->toBe(0);
});

it('rejects pool reuse when there are unread bytes or configured protocol callbacks', function (): void {
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    expect($pair)->toBeArray()->toHaveCount(2);
    [$socket, $peer] = $pair;
    $loop = new SelectLoop();
    $connection = new Connection($loop, $socket);
    $watchdog = $loop->delay(0.05, static function () use ($loop): void {
        $loop->stop();
    });

    try {
        $connection->onData(static function (): void {});
        expect($connection->isPoolIdleSafe())->toBeFalse();
        fwrite($peer, 'unconsumed-response');
        $loop->run();
        expect($connection->receivedBytes())->toBeGreaterThan(0)
            ->and($connection->isPoolIdleSafe())->toBeFalse();
        $connection->read();
        expect($connection->isPoolIdleSafe())->toBeFalse();
    } finally {
        $loop->cancel($watchdog);
        $connection->abort();
        fclose($peer);
    }
});
