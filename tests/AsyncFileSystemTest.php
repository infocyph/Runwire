<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Tests;

require_once __DIR__ . '/Support/ProcessFixture.php';

use Infocyph\Runwire\CancellationSource;
use Infocyph\Runwire\Exception\CancelledException;
use Infocyph\Runwire\Executor\ProcessExecutor;
use Infocyph\Runwire\Filesystem\AsyncFileSystem;

use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\RequestDeadline;
use Infocyph\Runwire\Runtime\Enum\CancellationReason;
use InvalidArgumentException;
use ReflectionProperty;
use RuntimeException;
use Throwable;

use function Infocyph\Runwire\Tests\Support\waitStatus;

it('persists binary offset writes and reads and renames through one bounded worker', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'runwire-async-file-');
    \PHPUnit\Framework\Assert::assertIsString($path);
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

        $result0 = $results['write'] ?? throw new RuntimeException('Missing write worker completion.');
        $result1 = $results['read'] ?? throw new RuntimeException('Missing read worker completion.');
        $result2 = $results['stat'] ?? throw new RuntimeException('Missing stat worker completion.');
        $result3 = $results['rename'] ?? throw new RuntimeException('Missing rename worker completion.');
        $result4 = $results['unlink'] ?? throw new RuntimeException('Missing unlink worker completion.');
        if (!is_int($result2[0]['size'] ?? null)) {
            throw new RuntimeException('Worker stat result did not include a numeric size.');
        }

        expect($result0)->toBe([5, null])
            ->and($result1)->toBe(["01ab\0cd", null])
            ->and($result2[0]['size'])->toBe(7)
            ->and($result2[1])->toBeNull()
            ->and($result3)->toBe([true, null])
            ->and($result4)->toBe([true, null])
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
    \PHPUnit\Framework\Assert::assertIsString($path);
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
    \PHPUnit\Framework\Assert::assertIsString($path);
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

        $writeChecksum = $checksums['write'] ?? throw new RuntimeException('Missing full-buffer write result.');
        $readChecksum = $checksums['read'] ?? throw new RuntimeException('Missing full-buffer read result.');
        expect($writeChecksum)->toBe([strlen($binary), null])
            ->and($readChecksum)->toBe([hash('sha256', $binary), strlen($binary), null])
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

/**
 * Pause the owned worker only after it has completed a real filesystem operation.
 * @throws \RuntimeException
 */
function pauseAsyncFileTestWorker(ProcessExecutor $executor): void
{
    $workers = (new ReflectionProperty(ProcessExecutor::class, 'workers'))->getValue($executor);
    \PHPUnit\Framework\Assert::assertIsArray($workers);
    \PHPUnit\Framework\Assert::assertCount(1, $workers);
    $worker = reset($workers);
    \PHPUnit\Framework\Assert::assertIsArray($worker);
    $process = $worker['process'] ?? null;
    \PHPUnit\Framework\Assert::assertIsResource($process);
    $pid = proc_get_status($process)['pid'];
    if ($pid < 2 || !posix_kill($pid, SIGSTOP)) {
        throw new RuntimeException('Unable to pause the owned filesystem worker.');
    }

    $deadline = hrtime(true) + 2_000_000_000;
    $status = 0;
    do {
        $waited = pcntl_waitpid($pid, $status, WNOHANG | WUNTRACED);
        if ($waited !== 0) {
            break;
        }
        usleep(1_000);
    } while (hrtime(true) < $deadline);

    if ($waited !== $pid || !pcntl_wifstopped(waitStatus($status))) {
        throw new RuntimeException('The owned filesystem worker did not stop.');
    }
}

it('retires an active filesystem read on a paused worker exactly once', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'runwire-active-file-');
    \PHPUnit\Framework\Assert::assertIsString($path);
    file_put_contents($path, 'fixture');

    $loop = new SelectLoop();
    $executor = new ProcessExecutor($loop, 1, 2);
    $filesystem = new AsyncFileSystem($executor);
    $callbacks = [];

    try {
        $filesystem->read(
            $path,
            1,
            static function (string $bytes, ?Throwable $error) use ($loop): void {
                expect($error)->toBeNull()->and($bytes)->toBe('f');
                $loop->stop();
            },
            deadline: RequestDeadline::afterSeconds(2.0, hrtime(true)),
        );
        $loop->run();
        pauseAsyncFileTestWorker($executor);

        $id = $filesystem->read(
            $path,
            1,
            static function (string $bytes, ?Throwable $error) use (&$callbacks): void {
                $callbacks[] = [$bytes, $error];
            },
        );
        // The executor's deferred pump assigns the read before this callback runs.
        $loop->defer(static function () use ($executor, $filesystem, $id, $loop): void {
            expect($executor->diagnostics()['active'])->toBe(1)
                ->and($filesystem->cancel($id))->toBeTrue()
                ->and($filesystem->cancel($id))->toBeFalse();
            $loop->stop();
        });
        $loop->run();

        expect($callbacks)->toHaveCount(1)
            ->and($callbacks[0][0])->toBe('')
            ->and($callbacks[0][1])->toBeInstanceOf(CancelledException::class)
            ->and($executor->diagnostics()['workers'])->toBe(0)
            ->and($executor->diagnostics()['active'])->toBe(0)
            ->and($loop->diagnostics()->readWatchers)->toBe(0)
            ->and($loop->diagnostics()->writeWatchers)->toBe(0)
            ->and($loop->diagnostics()->timersActive)->toBe(0);
    } finally {
        $executor->close();
        unlink($path);
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

        $replaced = $results['replace'] ?? throw new RuntimeException('Missing replacement completion.');
        $invalid = $results['invalid'] ?? throw new RuntimeException('Missing rejected rename completion.');
        expect($replaced)->toBe([true, null])
            ->and(file_exists($from))->toBeFalse()
            ->and(file_get_contents($to))->toBe("replacement\0bytes")
            ->and($invalid[0])->toBeFalse()
            ->and($invalid[1])->toBeInstanceOf(RuntimeException::class)
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
