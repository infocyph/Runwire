<?php

declare(strict_types=1);

use Infocyph\Runwire\Executor\ProcessExecutor;
use Infocyph\Runwire\Filesystem\AsyncFileSystem;
use Infocyph\Runwire\Loop\SelectLoop;

it('preserves real socket readiness during bounded worker-backed file transfers', function (): void {
    $directory = sys_get_temp_dir() . '/runwire-fs-socket-' . bin2hex(random_bytes(8));
    expect(mkdir($directory, 0700))->toBeTrue();
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    \PHPUnit\Framework\Assert::assertIsArray($pair);
    \PHPUnit\Framework\Assert::assertCount(2, $pair);
    [$reader, $writer] = $pair;
    stream_set_blocking($reader, false);
    stream_set_blocking($writer, false);

    $loop = new SelectLoop();
    $executor = new ProcessExecutor($loop, 2, 64);
    $filesystem = new AsyncFileSystem($executor, $directory);
    $payload = str_repeat('runwire-file-socket-', 2048);
    $files = 24;
    $progress = new class($files)
    {
        public function __construct(public int $pendingFiles) {}
        public int $received = 0;
        public int $socketBytesDuringFiles = 0;
        public int $sent = 0;
        public ?\Throwable $failure = null;
        public bool $timedOut = false;
    };
    $paths = [];
    $sendTimer = null;

    $finish = static function () use ($loop, $progress): void {
        if ($progress->pendingFiles === 0 && $progress->received === 40) {
            $loop->stop();
        }
    };
    $fail = static function (Throwable $error) use ($loop, $progress): void {
        $progress->failure ??= $error;
        $loop->stop();
    };
    $watcher = $loop->onReadable($reader, static function ($stream) use (
        $finish,
        $fail,
        $progress,
    ): void {
        $bytes = fread($stream, 4096);
        if (!is_string($bytes)) {
            $fail(new RuntimeException('Concurrent socket readiness could not be read.'));

            return;
        }
        if ($progress->pendingFiles > 0) {
            $progress->socketBytesDuringFiles += strlen($bytes);
        }
        $progress->received += strlen($bytes);
        $finish();
    });
    $deadline = $loop->delay(5.0, static function () use ($loop, $progress): void {
        $progress->timedOut = true;
        $loop->stop();
    });
    try {
        $sendTimer = $loop->repeat(0.004, static function (int $callbackId) use (
            $writer,
            $loop,
            $fail,
            $progress,
        ): void {
            if (fwrite($writer, 'x') !== 1) {
                $fail(new RuntimeException('Concurrent socket ping could not be sent.'));

                return;
            }

            ++$progress->sent;
            if ($progress->sent === 40) {
                $loop->cancel($callbackId);
            }
        });

        for ($index = 0; $index < $files; ++$index) {
            $path = $directory . '/file-' . $index;
            $paths[] = $path;
            $filesystem->write(
                $path,
                $payload,
                static function (int $written, ?Throwable $error) use (
                    $filesystem,
                    $path,
                    $payload,
                    $finish,
                    $fail,
                    $progress,
                ): void {
                    if ($error !== null || $written !== strlen($payload)) {
                        $fail($error ?? new RuntimeException('Mixed-load file write was incomplete.'));

                        return;
                    }
                    $filesystem->read(
                        $path,
                        strlen($payload),
                        static function (string $bytes, ?Throwable $readError) use (
                            $filesystem,
                            $path,
                            $payload,
                            $finish,
                            $fail,
                            $progress,
                        ): void {
                            if ($readError !== null || $bytes !== $payload) {
                                $fail($readError ?? new RuntimeException('Mixed-load file read was corrupted.'));

                                return;
                            }
                            $filesystem->unlink(
                                $path,
                                static function (bool $removed, ?Throwable $unlinkError) use (
                                    $finish,
                                    $fail,
                                    $progress,
                                ): void {
                                    if ($unlinkError !== null || !$removed) {
                                        $fail($unlinkError ?? new RuntimeException('Mixed-load file cleanup failed.'));

                                        return;
                                    }
                                    --$progress->pendingFiles;
                                    $finish();
                                },
                            );
                        },
                    );
                },
            );
        }

        $loop->run();
        expect($progress->failure)->toBeNull()
            ->and($progress->timedOut)->toBeFalse()
            ->and($progress->pendingFiles)->toBe(0)
            ->and($progress->sent)->toBe(40)
            ->and($progress->received)->toBe(40)
            ->and($progress->socketBytesDuringFiles)->toBeGreaterThan(0)
            ->and($executor->diagnostics()['completed'])->toBe($files * 3);
    } finally {
        if ($sendTimer !== null) {
            $loop->cancel($sendTimer);
        }
        $loop->cancel($watcher);
        $loop->cancel($deadline);
        $executor->close();
        fclose($reader);
        fclose($writer);
        foreach ($paths as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        rmdir($directory);
    }

    expect($loop->diagnostics()->readWatchers)->toBe(0)
        ->and($loop->diagnostics()->writeWatchers)->toBe(0)
        ->and($loop->diagnostics()->timersActive)->toBe(0)
        ->and($executor->diagnostics()['workers'])->toBe(0);
});
