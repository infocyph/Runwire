<?php

declare(strict_types=1);

use Infocyph\Runwire\CancellationSource;
use Infocyph\Runwire\Exception\CancelledException;
use Infocyph\Runwire\Executor\ProcessExecutor;
use Infocyph\Runwire\Filesystem\AsyncFileSystem;
use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\RequestDeadline;
use Infocyph\Runwire\Runtime\Enum\CancellationReason;

it('persists binary offset writes and reads and renames through one bounded worker', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'runwire-async-file-');
    expect($path)->toBeString();
    file_put_contents($path, '012345');
    $to = $path . '.moved';
    $loop = new SelectLoop();
    $executor = new ProcessExecutor($loop, 1, 8);
    $fs = new AsyncFileSystem($executor);
    $results = [];

    try {
        $fs->write($path, "ab\0cd", function (int $bytes, ?Throwable $error) use ($fs, $path, $to, &$results): void {
            $results['write'] = [$bytes, $error];
            $fs->read($path, 7, function (string $payload, ?Throwable $error) use ($fs, $path, $to, &$results): void {
                $results['read'] = [$payload, $error];
                $fs->stat($path, function (array $meta, ?Throwable $error) use ($fs, $path, $to, &$results): void {
                    $results['stat'] = [$meta, $error];
                    $fs->rename($path, $to, function (bool $renamed, ?Throwable $error) use ($fs, $to, &$results): void {
                        $results['rename'] = [$renamed, $error];
                        $fs->unlink($to, static function (bool $removed, ?Throwable $error) use (&$results): void {
                            $results['unlink'] = [$removed, $error];
                        });
                    });
                });
            });
        }, offset: 2);
        $executor->onDrained(static function () use ($executor): void {
            $executor->close();
        });
        $loop->run();

        expect($results['write'])->toBe([5, null])
            ->and($results['read'])->toBe(["01ab\0cd", null])
            ->and($results['stat'][0]['size'])->toBe(7)
            ->and($results['stat'][1])->toBeNull()
            ->and($results['rename'])->toBe([true, null])
            ->and($results['unlink'])->toBe([true, null])
            ->and(file_exists($path))->toBeFalse()
            ->and(file_exists($to))->toBeFalse()
            ->and($executor->diagnostics()['workers'])->toBe(0)
            ->and($loop->diagnostics()->readWatchers)->toBe(0)
            ->and($loop->diagnostics()->writeWatchers)->toBe(0);
    } finally {
        $executor->close();
        if (is_file($path)) {
            unlink($path);
        }
        if (is_file($to)) {
            unlink($to);
        }
    }
});

it('keeps timer heartbeat alive during queued off-loop file operations and retires canceled jobs exactly once', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'runwire-async-file-');
    expect($path)->toBeString();
    file_put_contents($path, 'fixture');
    $loop = new SelectLoop();
    $executor = new ProcessExecutor($loop, 1, 4);
    $fs = new AsyncFileSystem($executor);
    $source = new CancellationSource();
    $ticks = 0;
    $errors = [];
    $pulse = $loop->repeat(0.003, static function () use (&$ticks): void {
        ++$ticks;
    });

    try {
        $executor->submit('sleep', ['milliseconds' => 60], static function (): void {});
        $id = $fs->read($path, 7, static function (string $value, ?Throwable $error) use (&$errors): void {
            $errors[] = [$value, $error];
        }, token: $source->token(), deadline: RequestDeadline::afterSeconds(2.0, hrtime(true)));
        $source->cancel(CancellationReason::HOST_CANCELLED);
        expect($fs->cancel($id))->toBeFalse();
        $executor->onDrained(static function () use ($loop, $executor, $pulse): void {
            $loop->cancel($pulse);
            $executor->close();
        });
        $loop->run();

        expect($errors)->toHaveCount(1)
            ->and($errors[0][0])->toBe('')
            ->and($errors[0][1])->toBeInstanceOf(CancelledException::class)
            ->and($ticks)->toBeGreaterThan(4)
            ->and($source->token()->subscriptionCount())->toBe(0)
            ->and($loop->diagnostics()->readWatchers)->toBe(0)
            ->and($loop->diagnostics()->writeWatchers)->toBe(0)
            ->and($loop->diagnostics()->timersActive)->toBe(0);
    } finally {
        $source->dispose();
        $executor->close();
        unlink($path);
    }
});

it('rejects unbounded and malformed file operations before any worker dispatch', function (): void {
    $loop = new SelectLoop();
    $executor = new ProcessExecutor($loop, 1, 1);
    $fs = new AsyncFileSystem($executor);
    try {
        expect(fn() => $fs->read('', 1, static function (): void {}))->toThrow(InvalidArgumentException::class)
            ->and(fn() => $fs->read('/tmp/file', AsyncFileSystem::MAX_OPERATION_BYTES + 1, static function (): void {}))
            ->toThrow(InvalidArgumentException::class)
            ->and(fn() => $fs->write('/tmp/file', str_repeat('z', AsyncFileSystem::MAX_OPERATION_BYTES + 1), static function (): void {}))
            ->toThrow(InvalidArgumentException::class)
            ->and(fn() => $fs->rename('/tmp/file', "bad\0path", static function (): void {}))
            ->toThrow(InvalidArgumentException::class)
            ->and($executor->diagnostics()['submitted'])->toBe(0);
    } finally {
        $executor->close();
    }
});
