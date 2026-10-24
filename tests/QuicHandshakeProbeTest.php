<?php

declare(strict_types=1);

use Infocyph\Runwire\Exception\RuntimeUnavailableException;
use Infocyph\Runwire\Executor\ProcessExecutor;
use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Network\QuicClientOptions;
use Infocyph\Runwire\Network\QuicHandshakeProbe;
use Infocyph\Runwire\CancellationSource;
use Infocyph\Runwire\Exception\CancelledException;
use Infocyph\Runwire\RequestDeadline;
use Infocyph\Runwire\Runtime\Enum\CancellationReason;

it('bounds and rejects an unreachable native QUIC handshake off the loop without retaining workers', function (): void {
    $loop = new SelectLoop();
    $executor = new ProcessExecutor($loop, 1, 2);
    $probe = new QuicHandshakeProbe($executor);
    $calls = [];
    try {
        $probe->connect(
            '127.0.0.1',
            1,
            new QuicClientOptions('localhost', connectTimeoutMilliseconds: 60),
            static function ($value, ?Throwable $failure) use (&$calls, $executor): void {
                $calls[] = [$value, $failure];
                $executor->close();
            },
        );
        $loop->run();

        expect($calls)->toHaveCount(1)
            ->and($calls[0][0])->toBeNull()
            ->and($calls[0][1])->toBeInstanceOf(Throwable::class)
            ->and($executor->diagnostics()['active'])->toBe(0)
            ->and($executor->diagnostics()['workers'])->toBe(0)
            ->and($loop->diagnostics()->readWatchers)->toBe(0)
            ->and($loop->diagnostics()->writeWatchers)->toBe(0)
            ->and($loop->diagnostics()->timersActive)->toBe(0);
    } finally {
        $executor->close();
    }
});

it('fails closed on unsupported QUIC capabilities before starting the worker', function (): void {
    $loop = new SelectLoop();
    $executor = new ProcessExecutor($loop, 1, 1);
    $probe = new QuicHandshakeProbe($executor);
    try {
        expect(fn() => $probe->connect('example.com', 443,
            new QuicClientOptions('example.com', requireDatagrams: true),
            static function (): void {},
        ))->toThrow(RuntimeUnavailableException::class);
        expect(fn() => $probe->connect('example.com', 443,
            new QuicClientOptions('example.com', allowMigration: true),
            static function (): void {},
        ))->toThrow(RuntimeUnavailableException::class);
        expect(fn() => $probe->connect('example.com', 443,
            new QuicClientOptions('example.com', allowEarlyData: true),
            static function (): void {},
        ))->toThrow(RuntimeUnavailableException::class);
        expect($executor->diagnostics()['submitted'])->toBe(0);
    } finally {
        $executor->close();
    }
});


it('rejects invalid QUIC endpoint details before scheduling a worker', function (): void {
    $loop = new SelectLoop();
    $executor = new ProcessExecutor($loop, 1, 1);
    $probe = new QuicHandshakeProbe($executor);
    try {
        foreach (['', "bad\rhost", str_repeat('z', 254)] as $host) {
            expect(fn() => $probe->connect($host, 443, new QuicClientOptions('localhost'), static function (): void {}))
                ->toThrow(InvalidArgumentException::class);
        }
        foreach ([0, 65_536] as $port) {
            expect(fn() => $probe->connect('localhost', $port, new QuicClientOptions('localhost'), static function (): void {}))
                ->toThrow(InvalidArgumentException::class);
        }

        expect($executor->diagnostics()['submitted'])->toBe(0);
    } finally {
        $executor->close();
    }
});

it('cancels an off-loop QUIC probe exactly once and releases its token subscription', function (): void {
    $loop = new SelectLoop();
    $executor = new ProcessExecutor($loop, 1, 1);
    $source = new CancellationSource();
    $probe = new QuicHandshakeProbe($executor);
    $seen = [];

    try {
        $id = $probe->connect(
            '127.0.0.1',
            1,
            new QuicClientOptions('localhost', connectTimeoutMilliseconds: 2_000),
            static function (mixed $value, ?Throwable $error) use (&$seen): void {
                $seen[] = [$value, $error];
            },
            token: $source->token(),
        );
        expect($source->token()->subscriptionCount())->toBe(1);
        $source->cancel(CancellationReason::HOST_CANCELLED);
        expect($executor->cancel($id))->toBeFalse();
        $executor->close();
        $loop->run();

        expect($seen)->toHaveCount(1)
            ->and($seen[0][0])->toBeNull()
            ->and($seen[0][1])->toBeInstanceOf(CancelledException::class)
            ->and($seen[0][1]->reason)->toBe(CancellationReason::HOST_CANCELLED)
            ->and($source->token()->subscriptionCount())->toBe(0)
            ->and($executor->diagnostics()['active'])->toBe(0)
            ->and($loop->diagnostics()->timersActive)->toBe(0)
            ->and($loop->diagnostics()->readWatchers)->toBe(0)
            ->and($loop->diagnostics()->writeWatchers)->toBe(0);
    } finally {
        $executor->close();
        $source->dispose();
    }
});

it('applies the earlier caller deadline to a blocked native QUIC probe', function (): void {
    $loop = new SelectLoop();
    $executor = new ProcessExecutor($loop, 1, 1);
    $probe = new QuicHandshakeProbe($executor);
    $seen = [];
    $deadline = RequestDeadline::afterSeconds(0.06, hrtime(true));

    try {
        // Saturate the only worker first. The probe stays queued until its
        // deadline, so this is deterministic even without optional ext-quic.
        $executor->submit('sleep', ['milliseconds' => 180], static function (): void {});
        $probe->connect(
            '127.0.0.1',
            1,
            new QuicClientOptions('localhost', connectTimeoutMilliseconds: 2_000),
            static function (mixed $value, ?Throwable $error) use (&$seen, $executor): void {
                $seen[] = [$value, $error];
                $executor->close();
            },
            deadline: $deadline,
        );
        $loop->run();

        expect($seen)->toHaveCount(1)
            ->and($seen[0][0])->toBeNull()
            ->and($seen[0][1])->toBeInstanceOf(CancelledException::class)
            ->and($seen[0][1]->reason)->toBe(CancellationReason::DEADLINE_EXCEEDED)
            ->and($executor->diagnostics()['workers'])->toBe(0)
            ->and($loop->diagnostics()->timersActive)->toBe(0)
            ->and($loop->diagnostics()->readWatchers)->toBe(0)
            ->and($loop->diagnostics()->writeWatchers)->toBe(0);
    } finally {
        $executor->close();
    }
});
