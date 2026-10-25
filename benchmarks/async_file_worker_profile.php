<?php

declare(strict_types=1);

use Infocyph\Runwire\Executor\ProcessExecutor;
use Infocyph\Runwire\Filesystem\AsyncFileSystem;
use Infocyph\Runwire\Loop\SelectLoop;

/**
 * Bounded worker-backed filesystem performance profile, not release certification.
 *
 * Usage: php benchmarks/async_file_worker_profile.php [operations] [bytes]
 */
require dirname(__DIR__) . '/vendor/autoload.php';

$args = $_SERVER['argv'] ?? null;
if (!is_array($args) || count($args) > 3
    || (isset($args[1]) && !is_string($args[1]))
    || (isset($args[2]) && !is_string($args[2]))) {
    throw new InvalidArgumentException('Invalid filesystem profile arguments.');
}
$operations = isset($args[1]) ? filter_var($args[1], FILTER_VALIDATE_INT) : 32;
$bytes = isset($args[2]) ? filter_var($args[2], FILTER_VALIDATE_INT) : 16_384;
if (!is_int($operations) || $operations < 4 || $operations > 64
    || !is_int($bytes) || $bytes < 128 || $bytes > 65_536) {
    throw new InvalidArgumentException('Filesystem profile requires 4–64 operations and 128–65536 bytes.');
}

$directory = sys_get_temp_dir() . '/runwire-fs-profile-' . bin2hex(random_bytes(8));
if (!mkdir($directory, 0700) || !is_dir($directory)) {
    throw new RuntimeException('Unable to create filesystem profile directory.');
}
$loop = new SelectLoop();
$executor = new ProcessExecutor($loop, 2, 128);
$filesystem = new AsyncFileSystem($executor, $directory);
$payload = substr(str_repeat('runwire-fs-profile/', intdiv($bytes, 19) + 1), 0, $bytes);
$paths = [];
$remaining = $operations;
$completed = 0;
$failure = null;
$heartbeats = 0;
$maxBusyWorkers = 0;
$activeSampleSum = 0;
$heartbeatTimer = null;
$deadlineTimer = null;
$start = hrtime(true);
$fail = static function (Throwable $error) use (&$failure, $loop): void {
    $failure ??= $error;
    $loop->stop();
};
$tick = null;
$tick = static function () use (
    &$tick,
    &$heartbeatTimer,
    &$heartbeats,
    &$maxBusyWorkers,
    &$activeSampleSum,
    &$remaining,
    &$failure,
    $loop,
    $executor,
): void {
    ++$heartbeats;
    $active = $executor->diagnostics()['active'];
    $maxBusyWorkers = max($maxBusyWorkers, $active);
    $activeSampleSum += $active;
    if ($remaining > 0 && $failure === null) {
        $heartbeatTimer = $loop->delay(0.002, $tick);
    }
};

try {
    $heartbeatTimer = $loop->delay(0.002, $tick);
    $deadlineTimer = $loop->delay(30.0, static function () use ($fail): void {
        $fail(new RuntimeException('Filesystem worker profile exceeded the deadline.'));
    });
    for ($i = 0; $i < $operations; ++$i) {
        $path = $directory . '/item-' . $i;
        $paths[] = $path;
        $filesystem->write(
            $path,
            $payload,
            static function (int $written, ?Throwable $error) use (
                $filesystem,
                $path,
                $payload,
                $bytes,
                $fail,
                &$completed,
                &$remaining,
                $loop,
            ): void {
                if ($error !== null || $written !== $bytes) {
                    $fail($error ?? new RuntimeException('Filesystem worker wrote insufficient bytes.'));

                    return;
                }
                $filesystem->read(
                    $path,
                    $bytes,
                    static function (string $data, ?Throwable $error) use (
                        $filesystem,
                        $path,
                        $payload,
                        $fail,
                        &$completed,
                        &$remaining,
                        $loop,
                    ): void {
                        if ($error !== null || $data !== $payload) {
                            $fail($error ?? new RuntimeException('Filesystem worker readback mismatch.'));

                            return;
                        }
                        $filesystem->unlink(
                            $path,
                            static function (bool $removed, ?Throwable $error) use (
                                $fail,
                                &$completed,
                                &$remaining,
                                $loop,
                            ): void {
                                if ($error !== null || !$removed) {
                                    $fail($error ?? new RuntimeException('Filesystem worker unlink failed.'));

                                    return;
                                }
                                ++$completed;
                                --$remaining;
                                if ($remaining === 0) {
                                    $loop->stop();
                                }
                            },
                        );
                    },
                );
            },
        );
    }
    $loop->run();
    if ($failure !== null) {
        throw $failure;
    }
    if ($completed !== $operations) {
        throw new RuntimeException('Not all filesystem operations completed successfully.');
    }
    $elapsed = max(1, hrtime(true) - $start);
    $diagnostics = $executor->diagnostics();
    $usage = getrusage();
    $rss = is_array($usage)
        ? ($usage['ru_maxrss'] ?? 0) * (PHP_OS_FAMILY === 'Linux' ? 1024 : 1)
        : 0;
    $report = [
        'schemaVersion' => 1,
        'scope' => 'development-only-real-filesystem-worker-profile',
        'releaseCertified' => false,
        'operations' => $operations,
        'payloadBytes' => $bytes,
        'verifiedRoundTrips' => $completed,
        'bytesReadWritten' => $completed * $bytes * 2,
        'seconds' => round($elapsed / 1_000_000_000, 6),
        'throughputBytesPerSecond' => round($completed * $bytes * 2 * 1_000_000_000 / $elapsed, 2),
        'loopTicks' => $heartbeats,
        'sampledMaxBusyWorkers' => $maxBusyWorkers,
        'sampledWorkerOccupancyRatio' => round($activeSampleSum / max(1, $heartbeats * 2), 3),
        'executorPeakQueued' => $diagnostics['peakQueued'],
        'executorWorkers' => $diagnostics['workers'],
        'executorJobsCompleted' => $diagnostics['completed'],
        'phpHeapPeakBytes' => memory_get_peak_usage(true),
        'parentPhpPeakRssBytes' => $rss,
        'rssIncludesWorkerProcesses' => false,
        'correctnessPassed' => true,
    ];
    $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    if (fwrite(STDOUT, $json . PHP_EOL) === false) {
        throw new RuntimeException('Unable to emit filesystem worker profile.');
    }
} finally {
    if ($heartbeatTimer !== null) {
        $loop->cancel($heartbeatTimer);
    }
    if ($deadlineTimer !== null) {
        $loop->cancel($deadlineTimer);
    }
    $executor->close();
    foreach ($paths as $path) {
        if (is_file($path)) {
            unlink($path);
        }
    }
    rmdir($directory);
}
