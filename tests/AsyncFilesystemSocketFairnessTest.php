<?php

declare(strict_types=1);

use Infocyph\Runwire\Executor\ProcessExecutor;
use Infocyph\Runwire\Filesystem\AsyncFileSystem;
use Infocyph\Runwire\Loop\SelectLoop;

it('preserves real socket readiness during bounded worker-backed file transfers', function (): void {
    $directory = sys_get_temp_dir() . '/runwire-fs-socket-' . bin2hex(random_bytes(8));
    expect(mkdir($directory, 0700))->toBeTrue();
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    expect($pair)->toBeArray();
    [$reader, $writer] = $pair;
    stream_set_blocking($reader, false);
    stream_set_blocking($writer, false);

    $loop = new SelectLoop();
    $executor = new ProcessExecutor($loop, 2, 64);
    $filesystem = new AsyncFileSystem($executor, $directory);
    $payload = str_repeat('runwire-file-socket-', 2048);
    $files = 24;
    $pendingFiles = $files;
    $sent = 0;
    $received = 0;
    $socketBytesDuringFiles = 0;
    $failure = null;
    $timedOut = false;
    $paths = [];
    $sendTimer = null;

    $finish = static function () use (&$pendingFiles, &$received, $loop): void {
        if ($pendingFiles === 0 && $received === 40) {
            $loop->stop();
        }
    };
    $fail = static function (Throwable $error) use (&$failure, $loop): void {
        $failure ??= $error;
        $loop->stop();
    };
    $watcher = $loop->onReadable($reader, static function ($stream) use (
        &$received,
        &$socketBytesDuringFiles,
        &$pendingFiles,
        $finish,
        $fail,
    ): void {
        $bytes = fread($stream, 4096);
        if (!is_string($bytes)) {
            $fail(new RuntimeException('Concurrent socket readiness could not be read.'));

            return;
        }
        if ($pendingFiles > 0) {
            $socketBytesDuringFiles += strlen($bytes);
        }
        $received += strlen($bytes);
        $finish();
    });
    $deadline = $loop->delay(5.0, static function () use (&$timedOut, $loop): void {
        $timedOut = true;
        $loop->stop();
    });
    try {
        $sendTimer = $loop->repeat(0.004, static function () use (
            &$sendTimer,
            &$sent,
            $writer,
            $loop,
            $fail,
        ): void {
            if (fwrite($writer, 'x') !== 1) {
                $fail(new RuntimeException('Concurrent socket ping could not be sent.'));

                return;
            }

            ++$sent;
            if ($sent === 40) {
                $loop->cancel($sendTimer);
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
                    &$pendingFiles,
                    $finish,
                    $fail,
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
                            &$pendingFiles,
                            $finish,
                            $fail,
                        ): void {
                            if ($readError !== null || $bytes !== $payload) {
                                $fail($readError ?? new RuntimeException('Mixed-load file read was corrupted.'));

                                return;
                            }
                            $filesystem->unlink(
                                $path,
                                static function (bool $removed, ?Throwable $unlinkError) use (
                                    &$pendingFiles,
                                    $finish,
                                    $fail,
                                ): void {
                                    if ($unlinkError !== null || !$removed) {
                                        $fail($unlinkError ?? new RuntimeException('Mixed-load file cleanup failed.'));

                                        return;
                                    }
                                    --$pendingFiles;
                                    $finish();
                                },
                            );
                        },
                    );
                },
            );
        }

        $loop->run();
        expect($failure)->toBeNull()
            ->and($timedOut)->toBeFalse()
            ->and($pendingFiles)->toBe(0)
            ->and($sent)->toBe(40)
            ->and($received)->toBe(40)
            ->and($socketBytesDuringFiles)->toBeGreaterThan(0)
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
