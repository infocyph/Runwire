<?php

declare(strict_types=1);

use Infocyph\Runwire\CancellationSource;
use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Network\ConnectionFactory;
use Infocyph\Runwire\Network\Enum\CloseReason;
use Infocyph\Runwire\Network\Enum\ConnectionState;
use Infocyph\Runwire\RequestDeadline;
use Infocyph\Runwire\Runtime\Enum\CancellationReason;

it('cancels an owned stream exactly once and releases observers and watchers', function (): void {
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    expect($pair)->toBeArray();
    [$stream, $peer] = $pair;
    $loop = new SelectLoop();
    $connection = ConnectionFactory::accepted($loop, $stream);
    $source = new CancellationSource();
    $closeCount = 0;
    $connection->onClose(function () use (&$closeCount): void {
        ++$closeCount;
    });
    $connection->bindOwner($source->token());
    expect($source->token()->subscriptionCount())->toBe(1)
        ->and(fn() => $connection->bindOwner($source->token()))->toThrow(LogicException::class);

    $source->cancel(CancellationReason::HOST_CANCELLED);
    $connection->abort();
    expect($connection->state())->toBe(ConnectionState::CLOSED)
        ->and($connection->closeReason())->toBe(CloseReason::LOCAL_ABORT)
        ->and($closeCount)->toBe(1)
        ->and($source->token()->subscriptionCount())->toBe(0)
        ->and($loop->diagnostics()->readWatchers)->toBe(0)
        ->and($loop->diagnostics()->timersActive)->toBe(0);
    $source->dispose();
    fclose($peer);
});

it('closes on the earliest inherited monotonic owner deadline', function (): void {
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    expect($pair)->toBeArray();
    [$stream, $peer] = $pair;
    $loop = new SelectLoop();
    $connection = ConnectionFactory::accepted($loop, $stream);
    $source = new CancellationSource(RequestDeadline::afterSeconds(0.03, hrtime(true)));
    $connection->bindOwner($source->token(), RequestDeadline::afterSeconds(1.0, hrtime(true)));
    $loop->run();
    expect($connection->state())->toBe(ConnectionState::CLOSED)
        ->and($connection->closeReason())->toBe(CloseReason::LIFETIME_TIMEOUT)
        ->and($loop->diagnostics()->readWatchers)->toBe(0)
        ->and($loop->diagnostics()->timersActive)->toBe(0)
        ->and($source->token()->subscriptionCount())->toBe(0);
    $source->dispose();
    fclose($peer);
});


it('closes immediately for an already cancelled owner and never resurrects a timer', function (): void {
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    expect($pair)->toBeArray();
    [$stream, $peer] = $pair;
    $loop = new SelectLoop();
    $source = new CancellationSource();
    $source->cancel(CancellationReason::HOST_CANCELLED);
    $connection = ConnectionFactory::accepted($loop, $stream);
    $closes = 0;
    $connection->onClose(function () use (&$closes): void {
        ++$closes;
    });
    $connection->bindOwner($source->token(), RequestDeadline::afterSeconds(0.5, hrtime(true)));
    expect($connection->state())->toBe(ConnectionState::CLOSED)
        ->and($closes)->toBe(1)
        ->and($source->token()->subscriptionCount())->toBe(0)
        ->and($loop->diagnostics()->readWatchers)->toBe(0)
        ->and($loop->diagnostics()->timersActive)->toBe(0);
    $connection->abort();
    expect($closes)->toBe(1);
    $source->dispose();
    fclose($peer);
});

it('honors a shorter explicit connection deadline and frees its owner observer', function (): void {
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    expect($pair)->toBeArray();
    [$stream, $peer] = $pair;
    $loop = new SelectLoop();
    $source = new CancellationSource(RequestDeadline::afterSeconds(1.0, hrtime(true)));
    $connection = ConnectionFactory::accepted($loop, $stream);
    $connection->bindOwner($source->token(), RequestDeadline::afterSeconds(0.025, hrtime(true)));
    $loop->run();
    expect($connection->state())->toBe(ConnectionState::CLOSED)
        ->and($connection->closeReason())->toBe(CloseReason::LIFETIME_TIMEOUT)
        ->and($source->token()->subscriptionCount())->toBe(0)
        ->and($loop->diagnostics()->timersActive)->toBe(0);
    $source->dispose();
    fclose($peer);
});

it('transfers a live connection to a new token without closing on old owner cancellation', function (): void {
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    expect($pair)->toBeArray();
    [$stream, $peer] = $pair;
    $loop = new SelectLoop();
    $previous = new CancellationSource();
    $next = new CancellationSource();
    $connection = ConnectionFactory::accepted($loop, $stream);
    $connection->bindOwner($previous->token());

    try {
        $connection->transferOwner($next->token());
        expect($previous->token()->subscriptionCount())->toBe(0)
            ->and($next->token()->subscriptionCount())->toBe(1);
        $previous->cancel(CancellationReason::HOST_CANCELLED);
        expect($connection->state())->toBe(ConnectionState::OPEN);

        $next->cancel(CancellationReason::HOST_CANCELLED);
        expect($connection->closeReason())->toBe(CloseReason::LOCAL_ABORT)
            ->and($next->token()->subscriptionCount())->toBe(0)
            ->and($loop->diagnostics()->readWatchers)->toBe(0)
            ->and($loop->diagnostics()->timersActive)->toBe(0);
    } finally {
        $connection->abort();
        $previous->dispose();
        $next->dispose();
        fclose($peer);
    }
});

it('does not detach the previous owner when a transfer target is cancelled', function (): void {
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    expect($pair)->toBeArray();
    [$stream, $peer] = $pair;
    $loop = new SelectLoop();
    $previous = new CancellationSource();
    $next = new CancellationSource();
    $next->cancel(CancellationReason::HOST_CANCELLED);
    $connection = ConnectionFactory::accepted($loop, $stream);
    $connection->bindOwner($previous->token());

    try {
        expect(fn() => $connection->transferOwner($next->token()))->toThrow(
            \Infocyph\Runwire\Exception\CancelledException::class,
        );
        expect($connection->state())->toBe(ConnectionState::OPEN)
            ->and($previous->token()->subscriptionCount())->toBe(1);
        $previous->cancel(CancellationReason::HOST_CANCELLED);
        expect($connection->state())->toBe(ConnectionState::CLOSED)
            ->and($previous->token()->subscriptionCount())->toBe(0);
    } finally {
        $connection->abort();
        $previous->dispose();
        $next->dispose();
        fclose($peer);
    }
});


it('rebinds transferred connection deadline without inheriting the earlier request timeout', function (): void {
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    expect($pair)->toBeArray();
    [$stream, $peer] = $pair;
    $loop = new SelectLoop();
    $old = new CancellationSource(RequestDeadline::afterSeconds(0.01, hrtime(true)));
    $pool = new CancellationSource(RequestDeadline::afterSeconds(0.09, hrtime(true)));
    $connection = ConnectionFactory::accepted($loop, $stream);
    $stillOpenAtOldDeadline = false;

    try {
        $connection->bindOwner($old->token());
        $connection->transferOwner($pool->token());
        $loop->delay(0.035, static function () use (&$stillOpenAtOldDeadline, $connection): void {
            $stillOpenAtOldDeadline = $connection->state() === ConnectionState::OPEN;
        });
        $loop->run();

        expect($stillOpenAtOldDeadline)->toBeTrue()
            ->and($connection->closeReason())->toBe(CloseReason::LIFETIME_TIMEOUT)
            ->and($old->token()->subscriptionCount())->toBe(0)
            ->and($pool->token()->subscriptionCount())->toBe(0)
            ->and($loop->diagnostics()->timersActive)->toBe(0)
            ->and($loop->diagnostics()->readWatchers)->toBe(0);
    } finally {
        $connection->abort();
        $old->dispose();
        $pool->dispose();
        fclose($peer);
    }
});


it('does not accumulate close observers across repeated pooled-owner transfers', function (): void {
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    expect($pair)->toBeArray();
    [$stream, $peer] = $pair;
    $loop = new SelectLoop();
    $connection = ConnectionFactory::accepted($loop, $stream);
    $source = new CancellationSource();
    $connection->bindOwner($source->token());
    $callbackSlot = new ReflectionProperty($connection, 'closeCallbacks');
    $originalCallbacks = count($callbackSlot->getValue($connection));

    try {
        for ($i = 0; $i < 64; ++$i) {
            $next = new CancellationSource();
            $connection->transferOwner($next->token());
            expect($source->token()->subscriptionCount())->toBe(0);
            $source->dispose();
            $source = $next;
        }
        expect(count($callbackSlot->getValue($connection)))->toBe($originalCallbacks)
            ->and($connection->state())->toBe(ConnectionState::OPEN)
            ->and($source->token()->subscriptionCount())->toBe(1);

        $source->cancel(CancellationReason::HOST_CANCELLED);
        expect($connection->state())->toBe(ConnectionState::CLOSED)
            ->and($source->token()->subscriptionCount())->toBe(0)
            ->and($loop->diagnostics()->readWatchers)->toBe(0);
    } finally {
        $connection->abort();
        $source->dispose();
        fclose($peer);
    }
});
