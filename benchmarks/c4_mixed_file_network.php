<?php

declare(strict_types=1);

use Infocyph\Runwire\Executor\ProcessExecutor;
use Infocyph\Runwire\Filesystem\AsyncFileSystem;
use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Network\Connection;
use Infocyph\Runwire\Network\Dialer;

require dirname(__DIR__) . '/vendor/autoload.php';

/**
 * @param array<string, mixed> $usage
 * @throws RuntimeException
 */
function c4CpuMicroseconds(array $usage): int
{
    $userSeconds = $usage['ru_utime.tv_sec'] ?? null;
    $userMicros = $usage['ru_utime.tv_usec'] ?? null;
    $systemSeconds = $usage['ru_stime.tv_sec'] ?? null;
    $systemMicros = $usage['ru_stime.tv_usec'] ?? null;
    if (!is_int($userSeconds) || !is_int($userMicros)
        || !is_int($systemSeconds) || !is_int($systemMicros)) {
        throw new RuntimeException('C4 benchmark CPU metrics are unavailable.');
    }

    return ($userSeconds + $systemSeconds) * 1_000_000 + $userMicros + $systemMicros;
}

$listener = stream_socket_server('tcp://127.0.0.1:0', $code, $error);
if (!is_resource($listener)) {
    throw new RuntimeException('Real C4 network origin failed: ' . $error);
}
$address = stream_socket_get_name($listener, false);
if (!is_string($address)) {
    throw new RuntimeException('Real C4 network origin address unavailable.');
}
stream_set_blocking($listener, false);
$loop = new SelectLoop();
$executor = new ProcessExecutor($loop, 2, 128);
$files = new AsyncFileSystem($executor);
$dialer = new Dialer($loop, 64);
$paths = [];
$networkAttempted = 48;
$progress = new class {
    public int $fileCompleted = 0;

    public int $networkAccepted = 0;

    public int $networkConnected = 0;

    public bool $sleepFinished = false;
};
$errors = [];
$timerTicks = 0;
$maxGapMs = 0.0;
$lastTick = hrtime(true);
$maxFdCount = 0;
$start = hrtime(true);
$cpuStart = c4CpuMicroseconds(getrusage());
$networkLatency = [];
$runtimeBuild = getenv('RUNWIRE_RUNTIME_BUILD');
$listenerWatcher = $loop->onReadable($listener, static function () use ($listener, $progress): void {
    $accepted = stream_socket_accept($listener, 0);
    if (is_resource($accepted)) {
        ++$progress->networkAccepted;
        fclose($accepted);
    }
});
$pulseWatcher = $loop->repeat(0.002, static function () use (
    &$timerTicks,
    &$maxGapMs,
    &$lastTick,
    &$maxFdCount,
): void {
    ++$timerTicks;
    $now = hrtime(true);
    $maxGapMs = max($maxGapMs, ($now - $lastTick) / 1_000_000);
    $lastTick = $now;
    $fd = glob('/proc/self/fd/*');
    $maxFdCount = max($maxFdCount, is_array($fd) ? count($fd) : 0);
});
$doneWatcher = $loop->repeat(0.005, static function () use (
    $progress,
    $networkAttempted,
    $loop,
): void {
    if ($progress->fileCompleted === 48 && $progress->networkConnected === $networkAttempted
        && $progress->networkAccepted === $networkAttempted && $progress->sleepFinished) {
        $loop->stop();
    }
});
$timeoutWatcher = $loop->delay(12, static function () use ($loop, &$errors): void {
    $errors[] = 'mixed workload timeout';
    $loop->stop();
});
$payload = str_repeat("file-data\0", 4096);

try {
    $executor->submit('sleep', ['milliseconds' => 85], static function (mixed $value, ?Throwable $error) use (
        $progress,
        &$errors,
    ): void {
        $progress->sleepFinished = $error === null && $value !== false;
        if (!$progress->sleepFinished) {
            $errors[] = 'off-loop sleep failed';
        }
    });

    for ($i = 0; $i < 48; ++$i) {
        $path = sys_get_temp_dir() . '/runwire-c4-mix-' . bin2hex(random_bytes(8)) . '-' . $i;
        $paths[] = $path;
        $files->write($path, $payload, static function (int $count, ?Throwable $error) use (
            $files,
            $path,
            $payload,
            $progress,
            &$errors,
        ): void {
            if ($error !== null || $count !== strlen($payload)) {
                $errors[] = 'failed file write';
                ++$progress->fileCompleted;

                return;
            }
            $files->read($path, strlen($payload), static function (string $read, ?Throwable $error) use (
                $files,
                $path,
                $payload,
                $progress,
                &$errors,
            ): void {
                if ($error !== null || $read !== $payload) {
                    $errors[] = 'failed file read';
                }
                $files->unlink($path, static function (bool $removed, ?Throwable $error) use (
                    $progress,
                    &$errors,
                ): void {
                    if (!$removed || $error !== null) {
                        $errors[] = 'failed file unlink';
                    }
                    ++$progress->fileCompleted;
                });
            });
        });
    }

    for ($i = 0; $i < $networkAttempted; ++$i) {
        $started = hrtime(true);
        $dialer->connect('tcp://' . $address, static function (?Connection $connection, ?Throwable $error) use (
            $progress,
            &$networkLatency,
            &$errors,
            $started,
        ): void {
            $networkLatency[] = (hrtime(true) - $started) / 1_000_000;
            if ($error !== null || $connection === null) {
                $errors[] = 'failed TCP connection';
            } else {
                ++$progress->networkConnected;
            }
            $connection?->abort();
        });
    }

    $loop->run();
    $diag = $executor->diagnostics();
    $loopDiag = $loop->diagnostics();
    $elapsed = (hrtime(true) - $start) / 1_000_000_000;
    $cpuEnd = c4CpuMicroseconds(getrusage());
    sort($networkLatency, SORT_NUMERIC);
    $cpuSeconds = (float) ($cpuEnd - $cpuStart) / 1_000_000.0;
    $rss = (int) ((getrusage() ?: [])['ru_maxrss'] ?? 0) * 1024;
    $passed = $errors === [] && $progress->networkConnected === $networkAttempted
        && $progress->networkAccepted === $networkAttempted && $progress->fileCompleted === 48
        && $timerTicks >= 4 && $maxGapMs < 150 && $progress->sleepFinished
        && $maxFdCount > 0 && $maxFdCount < 256 && $rss > 0
        && $diag['queued'] === 0 && $dialer->pendingCount() === 0;
    $record = [
        'schema' => 1,
        'real_same_reactor_tcp_and_offloop_file_io' => true,
        'source_sha' => ($runtimeBuild === false || $runtimeBuild === '' || $runtimeBuild === '0' ? 'unknown' : $runtimeBuild),
        'network_attempted' => $networkAttempted,
        'network_accepted' => $progress->networkAccepted,
        'network_connected' => $progress->networkConnected,
        'completed_binary_file_roundtrips' => $progress->fileCompleted,
        'loop_ticks' => $timerTicks,
        'peak_timer_tick_gap_ms' => round($maxGapMs, 3),
        'network_p99_ms' => $networkLatency === [] ? null
            : round($networkLatency[max(0, (int) ceil((float) count($networkLatency) * 0.99) - 1)], 3),
        'elapsed_seconds' => round($elapsed, 4),
        'parent_cpu_percent' => round(100.0 * (float) $cpuSeconds / max($elapsed, 0.001), 3),
        'parent_peak_rss_bytes' => $rss,
        'max_parent_fds' => $maxFdCount,
        'executor_queued_after' => $diag['queued'],
        'dialer_pending_after' => $dialer->pendingCount(),
        'loop_read_watchers_before_cleanup' => $loopDiag->readWatchers,
        'errors' => $errors,
        'correctness_passed' => $passed,
    ];
    fwrite(STDOUT, json_encode($record, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . PHP_EOL);

    if (!$passed) {
        throw new RuntimeException('C4 genuine mixed reactor network and filesystem resource gate failed.');
    }
} finally {
    $loop->cancel($listenerWatcher);
    $loop->cancel($pulseWatcher);
    $loop->cancel($doneWatcher);
    $loop->cancel($timeoutWatcher);
    $dialer->close();
    $executor->close();
    fclose($listener);
    foreach ($paths as $path) {
        if (is_file($path)) {
            unlink($path);
        }
    }
}
