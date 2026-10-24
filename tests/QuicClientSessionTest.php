<?php

declare(strict_types=1);

use Infocyph\Runwire\CancellationSource;
use Infocyph\Runwire\Exception\CancelledException;
use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Network\Internal\QuicWorkerSession;
use Infocyph\Runwire\Network\QuicClientOptions;
use Infocyph\Runwire\Network\QuicClientSession;
use Infocyph\Runwire\Runtime\Enum\CancellationReason;

it('rejects stream commands before a verified worker session and after disposal', function (): void {
    $loop = new SelectLoop();
    $session = new QuicClientSession($loop, 2);
    try {
        expect($session->active())->toBeFalse()
            ->and(fn() => $session->command('open_bidi', 0, '', false, static function (): void {}))
            ->toThrow(LogicException::class, 'must be connected');
        $session->dispose();
        expect($session->active())->toBeFalse()
            ->and(fn() => $session->connect('localhost', 443, new QuicClientOptions('localhost'), static function (): void {}))
            ->toThrow(LogicException::class, 'already been opened or closed');
    } finally {
        $session->dispose();
    }
});

it('fails closed on unreachable off-loop QUIC session handshake without orphaning the loop', function (): void {
    $loop = new SelectLoop();
    $session = new QuicClientSession($loop, 2);
    $seen = [];
    try {
        $session->connect(
            '127.0.0.1',
            1,
            new QuicClientOptions('localhost', connectTimeoutMilliseconds: 80),
            static function (mixed $result, ?Throwable $error) use (&$seen): void {
                $seen[] = [$result, $error];
            },
        );
        $loop->run();
        expect($seen)->toHaveCount(1)
            ->and($seen[0][0])->toBeNull()
            ->and($seen[0][1])->toBeInstanceOf(Throwable::class)
            ->and($session->active())->toBeFalse()
            ->and($loop->diagnostics()->readWatchers)->toBe(0)
            ->and($loop->diagnostics()->writeWatchers)->toBe(0)
            ->and($loop->diagnostics()->timersActive)->toBe(0);
    } finally {
        $session->dispose();
    }
});

it('cancels a pending native session without retaining any worker-owned stream', function (): void {
    $loop = new SelectLoop();
    $session = new QuicClientSession($loop, 1);
    $source = new CancellationSource();
    $seen = [];
    try {
        $session->connect(
            '127.0.0.1',
            1,
            new QuicClientOptions('localhost', connectTimeoutMilliseconds: 2_000),
            static function (mixed $value, ?Throwable $error) use (&$seen): void {
                $seen[] = [$value, $error];
            },
            $source->token(),
        );
        $source->cancel(CancellationReason::HOST_CANCELLED);
        $loop->run();
        expect($seen)->toHaveCount(1)
            ->and($seen[0][0])->toBeNull()
            ->and($seen[0][1])->toBeInstanceOf(CancelledException::class)
            ->and($session->active())->toBeFalse()
            ->and($source->token()->subscriptionCount())->toBe(0)
            ->and($loop->diagnostics()->readWatchers)->toBe(0)
            ->and($loop->diagnostics()->writeWatchers)->toBe(0);
    } finally {
        $session->dispose();
        $source->dispose();
    }
});

it('rejects missing or stale native worker epochs and unsafe direct native configuration', function (): void {
    $worker = new QuicWorkerSession();
    expect(fn() => $worker->execute(['epoch' => 1, 'command' => '{"id":1}' . "\n"]))
        ->toThrow(RuntimeException::class, 'different epoch')
        ->and(fn() => $worker->execute(['action' => 'connect', 'host' => '127.0.0.1', 'port' => 443,
            'options' => ['verify_peer' => false], 'maxStreams' => 4]))
        ->toThrow(InvalidArgumentException::class, 'unsafe native QUIC');
});
