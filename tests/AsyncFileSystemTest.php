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

it('preserves a full 1 MiB binary file with SHA-256 parity through bounded offset worker writes and reads', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'runwire-async-binary-');
    expect($path)->toBeString();
    $binary = str_repeat("A\0\xff", 349_525) . 'A';
    expect(strlen($binary))->toBe(AsyncFileSystem::MAX_OPERATION_BYTES);
    $loop = new SelectLoop();
    $executor = new ProcessExecutor($loop, 1, 3);
    $filesystem = new AsyncFileSystem($executor);
    $checksums = [];

    try {
        $filesystem->write(
            $path,
            $binary,
            static function (int $written, ?Throwable $error) use (&$checksums, $binary, $filesystem, $path): void {
                $checksums['write'] = [$written, $error];
                $filesystem->read(
                    $path,
                    strlen($binary),
                    static function (string $bytes, ?Throwable $error) use (&$checksums): void {
                        $checksums['read'] = [hash('sha256', $bytes), strlen($bytes), $error];
                    },
                );
            },
        );
        $executor->onDrained(static function () use ($executor): void {
            $executor->close();
        });
        $loop->run();

        expect($checksums['write'])->toBe([strlen($binary), null])
            ->and($checksums['read'])->toBe([hash('sha256', $binary), strlen($binary), null])
            ->and($executor->diagnostics()['workers'])->toBe(0)
            ->and($loop->diagnostics()->readWatchers)->toBe(0)
            ->and($loop->diagnostics()->writeWatchers)->toBe(0);
    } finally {
        $executor->close();
        if (is_file($path)) {
            unlink($path);
        }
    }
});

it('retires a genuinely blocked active FIFO file reader on cancellation exactly once', function (): void {
    // Test dependencies include ext-posix; keep this a mandatory exercised fixture.
    expect(function_exists('posix_mkfifo'))->toBeTrue();

    $path = tempnam(sys_get_temp_dir(), 'runwire-fifo-job-');
    expect($path)->toBeString();
    unlink($path);
    expect(posix_mkfifo($path, 0600))->toBeTrue();

    $loop = new SelectLoop();
    $executor = new ProcessExecutor($loop, 1, 2);
    $filesystem = new AsyncFileSystem($executor);
    $callbacks = [];
    $activeObserved = false;
    $cancelSucceeded = false;

    try {
        $id = $filesystem->read(
            $path,
            1,
            static function (string $bytes, ?Throwable $error) use (&$callbacks): void {
                $callbacks[] = [$bytes, $error];
            },
        );
        $check = null;
        $check = $loop->repeat(0.01, static function () use (
            $loop,
            $executor,
            $filesystem,
            $id,
            &$check,
            &$activeObserved,
            &$cancelSucceeded,
        ): void {
            if ($executor->diagnostics()['active'] !== 1) {
                return;
            }
            $activeObserved = true;
            if (is_int($check)) {
                $loop->cancel($check);
            }
            $loop->delay(0.02, static function () use ($filesystem, $id, &$cancelSucceeded): void {
                $cancelSucceeded = $filesystem->cancel($id);
            });
        });
        $watchdog = $loop->delay(1.5, static function () use ($executor): void {
            $executor->close();
        });
        $executor->onDrained(static function () use ($executor, $loop, &$check, $watchdog): void {
            if (is_int($check)) {
                $loop->cancel($check);
            }
            $loop->cancel($watchdog);
            $executor->close();
        });

        $loop->run();

        expect($activeObserved)->toBeTrue()
            ->and($cancelSucceeded)->toBeTrue()
            ->and($callbacks)->toHaveCount(1)
            ->and($callbacks[0][0])->toBe('')
            ->and($callbacks[0][1])->toBeInstanceOf(CancelledException::class)
            ->and($executor->diagnostics()['workers'])->toBe(0)
            ->and($executor->diagnostics()['active'])->toBe(0)
            ->and($loop->diagnostics()->readWatchers)->toBe(0)
            ->and($loop->diagnostics()->writeWatchers)->toBe(0)
            ->and($loop->diagnostics()->timersActive)->toBe(0);
    } finally {
        $executor->close();
        if (is_file($path) || file_exists($path)) {
            unlink($path);
        }
    }
});

it('exposes host rename replacement and failed target path without pretending crash durability', function (): void {
    $directory = sys_get_temp_dir() . '/runwire-rename-' . bin2hex(random_bytes(6));
    expect(mkdir($directory, 0700))->toBeTrue();
    $from = $directory . '/source.bin';
    $to = $directory . '/target.bin';
    file_put_contents($from, "replacement\0bytes");
    file_put_contents($to, 'original');
    $loop = new SelectLoop();
    $executor = new ProcessExecutor($loop, 1, 3);
    $files = new AsyncFileSystem($executor);
    $results = [];

    try {
        $files->rename($from, $to, static function (bool $value, ?Throwable $error) use (&$results): void {
            $results['replace'] = [$value, $error];
        });
        $files->rename($to, $directory . '/nonexistent/invalid', static function (bool $value, ?Throwable $error) use (&$results): void {
            $results['invalid'] = [$value, $error];
        });
        $executor->onDrained(static function () use ($executor): void {
            $executor->close();
        });
        $loop->run();

        expect($results['replace'])->toBe([true, null])
            ->and(file_exists($from))->toBeFalse()
            ->and(file_get_contents($to))->toBe("replacement\0bytes")
            ->and($results['invalid'][0])->toBeFalse()
            ->and($results['invalid'][1])->toBeInstanceOf(RuntimeException::class)
            ->and($executor->diagnostics()['workers'])->toBe(0)
            ->and($loop->diagnostics()->readWatchers)->toBe(0)
            ->and($loop->diagnostics()->writeWatchers)->toBe(0);
    } finally {
        $executor->close();
        if (is_file($from)) {
            unlink($from);
        }
        if (is_file($to)) {
            unlink($to);
        }
        rmdir($directory);
    }
});

