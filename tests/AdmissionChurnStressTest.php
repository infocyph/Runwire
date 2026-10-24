<?php

declare(strict_types=1);

use Infocyph\Runwire\CancellationSource;
use Infocyph\Runwire\Exception\CancelledException;
use Infocyph\Runwire\Executor\ProcessExecutor;
use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Network\Dialer;
use Infocyph\Runwire\Network\DialOptions;
use Infocyph\Runwire\Runtime\Enum\CancellationReason;

it('enforces numeric TCP dial admission under 12 repeated full-capacity cancellation waves', function (): void {
    $listener = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $error);
    expect($listener)->toBeResource();
    stream_set_blocking($listener, false);
    $address = stream_socket_get_name($listener, false);
    expect($address)->toBeString();

    $loop = new SelectLoop();
    $dialer = new Dialer($loop, 8);
    $callbacks = [];
    $sources = [];
    $totalAttempts = 0;

    try {
        for ($wave = 0; $wave < 12; ++$wave) {
            $ids = [];
            for ($candidate = 0; $candidate < 8; ++$candidate) {
                $source = new CancellationSource();
                $sources[] = $source;
                $id = $dialer->connect(
                    'tcp://' . $address,
                    static function ($connection, ?Throwable $failure) use (&$callbacks): void {
                        $callbacks[] = [$connection, $failure];
                    },
                    new DialOptions(connectTimeoutSeconds: 1.0, cancellation: $source->token()),
                );
                $ids[] = $id;
                ++$totalAttempts;
            }
            expect($dialer->pendingCount())->toBe(8)
                ->and(fn() => $dialer->connect('tcp://' . $address, static function (): void {}))
                ->toThrow(OverflowException::class);

            foreach ($ids as $index => $id) {
                if ($index % 2 === 0) {
                    expect($dialer->cancel($id))->toBeTrue();
                } else {
                    $sources[$wave * 8 + $index]->cancel(CancellationReason::HOST_CANCELLED);
                }
                expect($dialer->cancel($id))->toBeFalse();
            }
            while (($accepted = stream_socket_accept($listener, 0)) !== false) {
                fclose($accepted);
            }
            expect($dialer->pendingCount())->toBe(0);
        }

        expect($totalAttempts)->toBe(96)
            ->and($callbacks)->toHaveCount(96)
            ->and($dialer->pendingCount())->toBe(0);
        foreach ($callbacks as [$connection, $error]) {
            expect($connection)->toBeNull()
                ->and($error)->toBeInstanceOf(CancelledException::class);
        }
        foreach ($sources as $source) {
            expect($source->token()->subscriptionCount())->toBe(0);
        }
    } finally {
        $dialer->close();
        foreach ($sources as $source) {
            $source->dispose();
        }
        fclose($listener);
    }

    expect($loop->diagnostics()->readWatchers)->toBe(0)
        ->and($loop->diagnostics()->writeWatchers)->toBe(0)
        ->and($loop->diagnostics()->timersActive)->toBe(0);
});

it('prevents executor queue overflow and callback duplication during cancellation churn', function (): void {
    $loop = new SelectLoop();
    $executor = new ProcessExecutor($loop, 1, 2);
    $results = [];

    try {
        for ($wave = 0; $wave < 12; ++$wave) {
            $ids = [];
            for ($job = 0; $job < 3; ++$job) {
                $ids[] = $executor->submit(
                    'sleep',
                    ['milliseconds' => 100],
                    static function ($value, ?Throwable $error) use (&$results): void {
                        $results[] = [$value, $error];
                    },
                );
            }
            expect(fn() => $executor->submit('sleep', ['milliseconds' => 1], static function (): void {}))
                ->toThrow(OverflowException::class);
            foreach ($ids as $id) {
                expect($executor->cancel($id))->toBeTrue()
                    ->and($executor->cancel($id))->toBeFalse();
            }
        }
        expect($results)->toHaveCount(36)
            ->and($executor->diagnostics()['cancelled'])->toBe(36);
        foreach ($results as [$value, $error]) {
            expect($value)->toBeNull()
                ->and($error)->toBeInstanceOf(CancelledException::class);
        }
    } finally {
        $executor->close();
    }

    expect($executor->diagnostics()['active'])->toBe(0)
        ->and($executor->diagnostics()['workers'])->toBe(0)
        ->and($loop->diagnostics()->readWatchers)->toBe(0)
        ->and($loop->diagnostics()->writeWatchers)->toBe(0)
        ->and($loop->diagnostics()->timersActive)->toBe(0);
});
