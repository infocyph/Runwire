<?php

declare(strict_types=1);

use Infocyph\Runwire\Loop\SelectLoop;

require_once dirname(__DIR__) . '/vendor/autoload.php';

/** @param list<float> $samples */
function r2MixedPercentile(array $samples, float $percentile): float
{
    if ($samples === [] || $percentile < 0 || $percentile > 1) {
        throw new InvalidArgumentException('Mixed pressure percentiles require observations and a valid fraction.');
    }

    sort($samples, SORT_NUMERIC);

    return round($samples[(int) floor((count($samples) - 1) * $percentile)], 3);
}

/** @return array<string, int|float|bool> */
function r2MixedPressureTier(int $cpuBurst, float $durationSeconds): array
{
    if ($cpuBurst < 1 || $cpuBurst > 192 || $durationSeconds < 0.1
        || $durationSeconds > 60 || !is_finite($durationSeconds)) {
        throw new InvalidArgumentException('Mixed scheduler pressure requires bounded duration and work.');
    }

    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    if (!is_array($pair) || count($pair) !== 2) {
        throw new RuntimeException('Unable to create local mixed pressure socket pair.');
    }

    [$reader, $writer] = $pair;
    stream_set_blocking($reader, false);
    stream_set_blocking($writer, false);
    $loop = new SelectLoop();
    $sent = 0;
    $received = 0;
    $cpuCalls = 0;
    $maxPending = 0;
    $pending = [];
    $latency = [];
    $sampledMemory = [];
    $lastCpuTimerIds = [];
    $started = hrtime(true);

    $readWatcher = $loop->onReadable($reader, static function (mixed $stream) use (&$received, &$pending, &$latency): void {
        $chunk = fread($stream, 8_192);
        if (!is_string($chunk) || $chunk === '') {
            throw new RuntimeException('Mixed pressure network watcher lost a real socket response.');
        }

        $now = hrtime(true);
        for ($index = 0, $length = strlen($chunk); $index < $length; ++$index) {
            $timestamp = array_shift($pending);
            if (!is_int($timestamp) || $chunk[$index] !== 'x') {
                throw new RuntimeException('Mixed pressure socket response was out of order or corrupted.');
            }
            $latency[] = ($now - $timestamp) / 1_000_000;
            ++$received;
        }
    });
    $timer = $loop->repeat(0.004, static function () use (
        $loop,
        $writer,
        $cpuBurst,
        &$sent,
        &$cpuCalls,
        &$pending,
        &$maxPending,
        &$sampledMemory,
        &$lastCpuTimerIds,
    ): void {
        if (count($pending) > 8) {
            throw new RuntimeException('Mixed pressure network backlog exceeded its safety bound.');
        }
        $timestamp = hrtime(true);
        if (fwrite($writer, 'x') !== 1) {
            throw new RuntimeException('Mixed pressure socket write was incomplete.');
        }
        $pending[] = $timestamp;
        ++$sent;
        $maxPending = max($maxPending, count($pending));
        $lastCpuTimerIds = [];
        for ($index = 0; $index < $cpuBurst; ++$index) {
            $lastCpuTimerIds[] = $loop->delay(0.0, static function () use (&$cpuCalls): void {
                hash('sha256', 'runwire-mixed-scheduler-' . $cpuCalls);
                ++$cpuCalls;
            });
        }
        $sampledMemory[] = memory_get_usage(true);
    });
    $stop = $loop->delay($durationSeconds, static function () use ($loop): void {
        $loop->stop();
    });

    try {
        $loop->run();
    } finally {
        $loop->cancel($timer);
        foreach ($lastCpuTimerIds as $cpuTimerId) {
            $loop->cancel($cpuTimerId);
        }
        $loop->cancel($readWatcher);
        $loop->cancel($stop);
        fclose($reader);
        fclose($writer);
    }

    $diagnostics = $loop->diagnostics();
    $elapsed = (hrtime(true) - $started) / 1_000_000_000;
    $pendingCount = count($pending);
    $correct = $sent >= 5
        && $received >= $sent - 1
        && $pendingCount <= 1
        && $cpuCalls >= ($sent - 1) * $cpuBurst
        && $diagnostics->timersActive === 0
        && $diagnostics->readWatchers === 0
        && $diagnostics->writeWatchers === 0
        && $diagnostics->deferredBacklog === 0
        && count($latency) >= 4;
    if (!$correct) {
        throw new RuntimeException(sprintf(
            'Mixed scheduler invariant failed: sent=%d read=%d pending=%d cpu=%d expected_cpu=%d timers=%d read_watchers=%d write_watchers=%d deferred=%d latency_samples=%d.',
            $sent,
            $received,
            $pendingCount,
            $cpuCalls,
            ($sent - 1) * $cpuBurst,
            $diagnostics->timersActive,
            $diagnostics->readWatchers,
            $diagnostics->writeWatchers,
            $diagnostics->deferredBacklog,
            count($latency),
        ));
    }

    $p99 = r2MixedPercentile($latency, 0.99);
    if ($p99 > 500.0) {
        throw new RuntimeException('Mixed scheduler p99 socket progress exceeded 500 ms.');
    }

    return [
        'cpu_callbacks_per_tick' => $cpuBurst,
        'duration_seconds' => round($elapsed, 3),
        'socket_writes' => $sent,
        'socket_reads' => $received,
        'pending_socket_bytes' => $pendingCount,
        'max_pending_bytes' => $maxPending,
        'cpu_callbacks' => $cpuCalls,
        'readable_p50_ms' => r2MixedPercentile($latency, 0.5),
        'readable_p95_ms' => r2MixedPercentile($latency, 0.95),
        'readable_p99_ms' => $p99,
        'max_tick_ms' => round($diagnostics->maxTickNanoseconds / 1_000_000, 3),
        'max_timer_lag_ms' => round($diagnostics->maxLagNanoseconds / 1_000_000, 3),
        'max_sampled_php_bytes' => max($sampledMemory),
        'memory_drift_bytes' => max($sampledMemory) - min($sampledMemory),
        'watchers_after' => $diagnostics->readWatchers + $diagnostics->writeWatchers,
        'timers_after' => $diagnostics->timersActive,
        'deferred_after' => $diagnostics->deferredBacklog,
        'correctness_passed' => true,
    ];
}

/** @return array<string, mixed> */
function runwireR2MixedSchedulerSoak(float $durationSeconds = 2.0): array
{
    $tiers = [];
    foreach ([8, 32, 96] as $cpuBurst) {
        $tiers[] = r2MixedPressureTier($cpuBurst, $durationSeconds);
    }

    return [
        'schema' => 1,
        'profile' => 'mixed-real-socket-timers-cpu',
        'runtime_build' => getenv('RUNWIRE_RUNTIME_BUILD') ?: 'local-unpinned',
        'php_version' => PHP_VERSION,
        'backend' => 'select-loop',
        'tier_count' => count($tiers),
        'duration_seconds_per_tier' => $durationSeconds,
        'real_socket_io' => true,
        'timers_and_cpu' => true,
        'tiers' => $tiers,
        'correctness_passed' => true,
    ];
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    fwrite(STDOUT, json_encode(
        runwireR2MixedSchedulerSoak((float) ($argv[1] ?? 2.0)),
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
    ) . PHP_EOL);
}
