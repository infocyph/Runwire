<?php

declare(strict_types=1);

use Infocyph\Runwire\Executor\ProcessExecutor;
use Infocyph\Runwire\Loop\SelectLoop;

require_once dirname(__DIR__) . '/vendor/autoload.php';

/** @param list<float> $sorted */
function u01Percentile(array $sorted, float $fraction): float
{
    return round($sorted[(int) floor((count($sorted) - 1) * $fraction)], 3);
}

/** @param array<string, int> $usage */
function u01CpuMilliseconds(array $usage, string $prefix): float
{
    return (float) (($usage[$prefix . '.tv_sec'] ?? 0) * 1_000)
        + (float) (($usage[$prefix . '.tv_usec'] ?? 0) / 1_000);
}

$loop = new SelectLoop();
$executor = new ProcessExecutor($loop, 2, 32);
$jobs = 32;
$completed = 0;
$failed = 0;
$ticks = 0;
$latency = [];
$starts = [];
$beforeCpu = getrusage();
if (!is_array($beforeCpu)) {
    throw new RuntimeException('U01 profiling requires PHP getrusage().');
}
$begin = hrtime(true);

$ticker = $loop->repeat(0.003, static function () use (&$ticks): void {
    ++$ticks;
});
for ($job = 0; $job < $jobs; ++$job) {
    $starts[$job] = hrtime(true);
    $executor->submit('sleep', ['milliseconds' => 12], static function ($value, $error) use (
        &$completed,
        &$failed,
        &$latency,
        $starts,
        $job,
    ): void {
        if ($error !== null || $value !== 12) {
            ++$failed;

            return;
        }
        ++$completed;
        $latency[] = (hrtime(true) - $starts[$job]) / 1_000_000;
    });
}
$executor->onDrained(static function () use ($executor, $loop, $ticker): void {
    $loop->cancel($ticker);
    $executor->close();
});
$loop->run();
$elapsedSeconds = (hrtime(true) - $begin) / 1_000_000_000;
$afterCpu = getrusage();
if (!is_array($afterCpu)) {
    throw new RuntimeException('U01 profiling could not capture final CPU usage.');
}
sort($latency, SORT_NUMERIC);
$stats = $executor->diagnostics();
$profile = [
    'schema' => 1,
    'runtime_build' => getenv('RUNWIRE_RUNTIME_BUILD') ?: 'local',
    'php' => PHP_VERSION,
    'method' => 'end-to-end bounded off-loop PHP subprocess IPC fixture',
    'real_network_included' => false,
    'jobs_submitted' => $jobs,
    'jobs_completed' => $completed,
    'jobs_failed' => $failed,
    'pending_after' => $stats['active'] + $stats['queued'],
    'worker_count_after' => $stats['workers'],
    'loop_ticks' => $ticks,
    'elapsed_seconds' => round($elapsedSeconds, 4),
    'operations_per_second' => round($completed / max(0.000001, $elapsedSeconds), 2),
    'latency' => [
        'p50_ms' => $latency !== [] ? u01Percentile($latency, 0.50) : 0.0,
        'p95_ms' => $latency !== [] ? u01Percentile($latency, 0.95) : 0.0,
        'p99_ms' => $latency !== [] ? u01Percentile($latency, 0.99) : 0.0,
    ],
    'cpu' => [
        'parent_user_ms' => round(max(0.0, u01CpuMilliseconds($afterCpu, 'ru_utime') - u01CpuMilliseconds($beforeCpu, 'ru_utime')), 3),
        'parent_system_ms' => round(max(0.0, u01CpuMilliseconds($afterCpu, 'ru_stime') - u01CpuMilliseconds($beforeCpu, 'ru_stime')), 3),
        'includes_child_processes' => false,
    ],
    'memory' => [
        'parent_peak_allocated_bytes' => memory_get_peak_usage(true),
        'includes_child_processes' => false,
    ],
    'correctness_passed' => $completed === $jobs && $failed === 0
        && $stats['active'] === 0 && $stats['queued'] === 0
        && $stats['workers'] === 0 && $ticks > 0,
];
$json = json_encode($profile, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
if (fwrite(STDOUT, $json . "\n") === false) {
    throw new RuntimeException('Cannot write executor benchmark evidence.');
}
if (!$profile['correctness_passed']) {
    throw new RuntimeException('Executor benchmark correctness or resource cleanup failed.');
}
