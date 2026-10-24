<?php

declare(strict_types=1);

use Infocyph\Runwire\Exception\RuntimeUnavailableException;
use Infocyph\Runwire\Executor\ProcessExecutor;
use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Network\QuicClientOptions;
use Infocyph\Runwire\Network\QuicHandshakeProbe;
use Infocyph\Runwire\RequestDeadline;

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
