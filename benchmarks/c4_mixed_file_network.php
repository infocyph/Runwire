<?php

declare(strict_types=1);

use Infocyph\Runwire\Executor\ProcessExecutor;
use Infocyph\Runwire\Filesystem\AsyncFileSystem;
use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Network\Connection;
use Infocyph\Runwire\Network\Dialer;

require dirname(__DIR__) . '/vendor/autoload.php';

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
$networkConnected = 0;
$networkAccepted = 0;
$fileCompleted = 0;
$errors = [];
$timerTicks = 0;
$maxGapMs = 0.0;
$lastTick = hrtime(true);
$maxFdCount = 0;
$sleepFinished = false;
$start = hrtime(true);
$cpuStart = getrusage();
$networkLatency = [];
$listenerWatcher = $loop->onReadable($listener, static function () use ($listener, &$networkAccepted): void {
    while (is_resource($accepted = @stream_socket_accept($listener, 0))) {
        ++$networkAccepted;
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
    &$fileCompleted,
    &$networkConnected,
    &$networkAccepted,
    &$sleepFinished,
    $networkAttempted,
    $loop,
): void {
    if ($fileCompleted === 48 && $networkConnected === $networkAttempted
        && $networkAccepted === $networkAttempted && $sleepFinished) {
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
        &$sleepFinished,
        &$errors,
    ): void {
        $sleepFinished = $error === null;
        if (!$sleepFinished) {
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
            &$fileCompleted,
            &$errors,
        ): void {
            if ($error !== null || $count !== strlen($payload)) {
                $errors[] = 'failed file write';
                ++$fileCompleted;

                return;
            }
            $files->read($path, strlen($payload), static function (string $read, ?Throwable $error) use (
                $files,
                $path,
                $payload,
                &$fileCompleted,
                &$errors,
            ): void {
                if ($error !== null || $read !== $payload) {
                    $errors[] = 'failed file read';
                }
                $files->unlink($path, static function (bool $removed, ?Throwable $error) use (
                    &$fileCompleted,
                    &$errors,
                ): void {
                    if (!$removed || $error !== null) {
                        $errors[] = 'failed file unlink';
                    }
                    ++$fileCompleted;
                });
            });
        });
    }
    for ($i = 0; $i < $networkAttempted; ++$i) {
        $started = hrtime(true);
        $dialer->connect('tcp://' . $address, static function (?Connection $connection, ?Throwable $error) use (
            &$networkConnected,
            &$networkLatency,
            &$errors,
            $started,
        ): void {
            $networkLatency[] = (hrtime(true) - $started) / 1_000_000;
            if ($error !== null || $connection === null) {
                $errors[] = 'failed TCP connection';
            } else {
                ++$networkConnected;
            }
            $connection?->abort();
        });
    }
    $loop->run();
    $diag = $executor->diagnostics();
    $loopDiag = $loop->diagnostics();
    $elapsed = (hrtime(true) - $start) / 1_000_000_000;
    $cpuEnd = getrusage();
    sort($networkLatency, SORT_NUMERIC);
    $cpuSeconds = ((int) ($cpuEnd['ru_utime.tv_sec'] ?? 0) - (int) ($cpuStart['ru_utime.tv_sec'] ?? 0))
        + ((int) ($cpuEnd['ru_utime.tv_usec'] ?? 0) - (int) ($cpuStart['ru_utime.tv_usec'] ?? 0)) / 1_000_000
        + ((int) ($cpuEnd['ru_stime.tv_sec'] ?? 0) - (int) ($cpuStart['ru_stime.tv_sec'] ?? 0))
        + ((int) ($cpuEnd['ru_stime.tv_usec'] ?? 0) - (int) ($cpuStart['ru_stime.tv_usec'] ?? 0)) / 1_000_000;
    $rss = (int) (getrusage()['ru_maxrss'] ?? 0) * 1024;
    $passed = $errors === [] && $networkConnected === $networkAttempted
        && $networkAccepted === $networkAttempted && $fileCompleted === 48
        && $timerTicks >= 4 && $maxGapMs < 150 && $sleepFinished
        && $maxFdCount > 0 && $maxFdCount < 256 && $rss > 0
        && $diag['queued'] === 0 && $dialer->pendingCount() === 0;
    $record = [
        'schema' => 1,
        'real_same_reactor_tcp_and_offloop_file_io' => true,
        'source_sha' => getenv('GITHUB_SHA') ?: 'git-worker',
        'network_attempted' => $networkAttempted,
        'network_accepted' => $networkAccepted,
        'network_connected' => $networkConnected,
        'completed_binary_file_roundtrips' => $fileCompleted,
        'loop_ticks' => $timerTicks,
        'peak_timer_tick_gap_ms' => round($maxGapMs, 3),
        'network_p99_ms' => $networkLatency === [] ? null
            : round($networkLatency[max(0, (int) ceil(count($networkLatency) * 0.99) - 1)], 3),
        'elapsed_seconds' => round($elapsed, 4),
        'parent_cpu_percent' => round(100 * $cpuSeconds / max($elapsed, 0.001), 3),
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
