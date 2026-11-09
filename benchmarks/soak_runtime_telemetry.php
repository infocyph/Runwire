<?php

declare(strict_types=1);

require_once __DIR__ . '/cli_local_paths.php';

/**
 * @return array<string, int|bool>
 * @throws \RuntimeException
 */
function soakRuntimeSnapshot(): array
{
    $gc = gc_status();
    $opcache = function_exists('opcache_get_status') ? opcache_get_status(false) : false;
    $memory = is_array($opcache) ? ($opcache['memory_usage'] ?? null) : null;
    $statistics = is_array($opcache) ? ($opcache['opcache_statistics'] ?? null) : null;
    $usedBytes = is_array($memory) ? ($memory['used_memory'] ?? 0) : 0;
    $cachedScripts = is_array($statistics) ? ($statistics['num_cached_scripts'] ?? 0) : 0;
    if (!is_int($usedBytes) || !is_int($cachedScripts)) {
        throw new RuntimeException('OPcache metrics must contain non-negative integer counters.');
    }

    return [
        'allocated_bytes' => memory_get_usage(true),
        'peak_allocated_bytes' => memory_get_peak_usage(true),
        'gc_enabled' => gc_enabled(),
        'gc_runs' => $gc['runs'],
        'gc_collected' => $gc['collected'],
        'gc_roots' => $gc['roots'],
        'opcache_enabled' => is_array($opcache) && ($opcache['opcache_enabled'] ?? false) === true,
        'opcache_used_bytes' => $usedBytes,
        'opcache_cached_scripts' => $cachedScripts,
    ];
}

/** @param list<array<string,int|float|bool>> $rows */
function soakRuntimeMedian(array $rows, string $key): float
{
    if ($rows === []) {
        return 0.0;
    }
    $values = array_map(static fn(array $row): int => (int) $row[$key], $rows);
    sort($values, SORT_NUMERIC);
    $at = intdiv(count($values), 2);

    return count($values) % 2 === 0
        ? ($values[$at - 1] + $values[$at]) / 2
        : (float) $values[$at];
}

/** @param array<string,int|float|bool> $sample */
function soakRuntimeSampleValid(array $sample, float $elapsed, float $previousElapsed, int $allocated, int $used, int $gcRoots, int $gcRuns, int $previousGcRuns, int $gcCollected, int $previousGcCollected): bool
{
    return !(!is_finite($elapsed) || $elapsed <= $previousElapsed
            || $allocated <= 0 || $used < 0 || $gcRoots < 0 || $gcRuns < $previousGcRuns
            || $gcCollected < $previousGcCollected
            || ($sample['gc_enabled'] ?? false) !== true
            || ($sample['opcache_enabled'] ?? false) !== true);
}

/**
 * @param list<array<string,int|float|bool>> $samples
 * @return array{early:list<array<string,int|float|bool>>,late:list<array<string,int|float|bool>>,valid:bool}
 */
function soakRuntimeWindows(array $samples, float $duration): array
{
    $early = [];
    $late = [];
    $previousElapsed = -1.0;
    $previousGcRuns = -1;
    $previousGcCollected = -1;
    $valid = true;

    foreach ($samples as $sample) {
        $elapsed = (float) ($sample['elapsed_seconds'] ?? -1);
        $allocated = (int) ($sample['allocated_bytes'] ?? 0);
        $used = (int) ($sample['opcache_used_bytes'] ?? 0);
        $gcRoots = (int) ($sample['gc_roots'] ?? -1);
        $gcRuns = (int) ($sample['gc_runs'] ?? -1);
        $gcCollected = (int) ($sample['gc_collected'] ?? -1);

        if (!soakRuntimeSampleValid($sample, $elapsed, $previousElapsed, $allocated, $used, $gcRoots, $gcRuns, $previousGcRuns, $gcCollected, $previousGcCollected)) {
            $valid = false;

            break;
        }

        $previousElapsed = $elapsed;
        $previousGcRuns = $gcRuns;
        $previousGcCollected = $gcCollected;
        if ($elapsed >= $duration * 0.15 && $elapsed <= $duration * 0.35) {
            $early[] = $sample;
        }
        if ($elapsed >= $duration * 0.70 && $elapsed <= $duration * 0.90) {
            $late[] = $sample;
        }
    }

    return compact('early', 'late', 'valid');
}

/**
 * @param list<array<string, int|float|bool>> $samples
 * @return array<string, int|float|bool|string>
 * @throws \InvalidArgumentException
 */
function soakAnalyzeRuntimeSnapshots(array $samples, float $minimumDurationSeconds = 1_740.0): array
{
    if ($minimumDurationSeconds <= 0 || !is_finite($minimumDurationSeconds)) {
        throw new InvalidArgumentException('Internal soak minimum duration must be positive and finite.');
    }

    $duration = $samples === [] ? 0.0 : (float) $samples[count($samples) - 1]['elapsed_seconds'];
    ['early' => $early, 'late' => $late, 'valid' => $valid] = soakRuntimeWindows($samples, $duration);

    $enough = $valid && $duration >= $minimumDurationSeconds
        && count($samples) >= 100 && count($early) >= 8 && count($late) >= 8;

    $earlyHeap = soakRuntimeMedian($early, 'allocated_bytes');
    $lateHeap = soakRuntimeMedian($late, 'allocated_bytes');
    $earlyRoots = soakRuntimeMedian($early, 'gc_roots');
    $lateRoots = soakRuntimeMedian($late, 'gc_roots');
    $earlyCache = soakRuntimeMedian($early, 'opcache_used_bytes');
    $lateCache = soakRuntimeMedian($late, 'opcache_used_bytes');
    $passed = $enough
        && $lateHeap <= $earlyHeap * 1.15 + 16_777_216.0
        && $lateRoots <= $earlyRoots + 2_048.0
        && $lateCache <= $earlyCache + 8_388_608.0;

    return [
        'passed' => $passed,
        'status' => !$enough ? 'insufficient_or_invalid_evidence' : ($passed ? 'passed' : 'retained_state_growth'),
        'samples' => count($samples),
        'duration_seconds' => round($duration, 3),
        'early_heap_median_bytes' => (int) round($earlyHeap),
        'late_heap_median_bytes' => (int) round($lateHeap),
        'early_gc_roots_median' => (int) round($earlyRoots),
        'late_gc_roots_median' => (int) round($lateRoots),
        'early_opcache_used_median_bytes' => (int) round($earlyCache),
        'late_opcache_used_median_bytes' => (int) round($lateCache),
        'gc_collected_total_at_end' => (int) ($samples[count($samples) - 1]['gc_collected'] ?? 0),
        'gc_runs_total_at_end' => (int) ($samples[count($samples) - 1]['gc_runs'] ?? 0),
        'opcache_cached_scripts_at_end' => (int) ($samples[count($samples) - 1]['opcache_cached_scripts'] ?? 0),
    ];
}

/**
 * @return list<array<string, int|float|bool>>
 * @throws \RuntimeException
 */
function soakReadRuntimeSnapshots(string $path): array
{
    $path = runwireBenchmarkReadablePath($path);
    $file = fopen($path, 'rb');
    if (!is_resource($file)) {
        throw new RuntimeException('Internal soak JSONL is unreadable.');
    }

    $records = [];

    try {
        while (($line = fgets($file)) !== false) {
            $record = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
            if (!is_array($record)) {
                throw new RuntimeException('Internal soak JSONL row is not an object.');
            }
            $validated = [];
            foreach ($record as $name => $value) {
                if (!is_string($name) || (!is_int($value) && !is_float($value) && !is_bool($value))) {
                    throw new RuntimeException('Internal soak telemetry must contain named scalar metrics.');
                }
                $validated[$name] = $value;
            }
            $records[] = $validated;
        }
    } finally {
        fclose($file);
    }

    return $records;
}

/**
 * @throws \InvalidArgumentException
 * @throws \RuntimeException
 */
function soakSampleRuntimeHttp(int $port, int $rootPid, string $path, int $interval): void
{
    if ($port < 1 || $port > 65_535 || $rootPid < 1 || $interval < 1 || $interval > 60) {
        throw new InvalidArgumentException('Invalid internal telemetry sampler arguments.');
    }

    $path = runwireBenchmarkOutputPath($path);
    $interval = runwireBenchmarkSampleInterval($interval);
    $file = fopen($path, 'wb');
    if (!is_resource($file)) {
        throw new RuntimeException('Cannot write internal runtime telemetry.');
    }

    try {
        $start = hrtime(true);
        while (is_dir('/proc/' . $rootPid)) {
            $context = stream_context_create(['http' => [
                'timeout' => 2.0,
                'ignore_errors' => false,
                'header' => "Connection: close\r\n",
            ]]);
            $body = file_get_contents('http://127.0.0.1:' . $port . '/__runwire_soak_diagnostics', false, $context);
            if (!is_string($body)) {
                throw new RuntimeException('Runwire worker diagnostics endpoint did not respond.');
            }
            $snapshot = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
            if (!is_array($snapshot)) {
                throw new RuntimeException('Runwire worker diagnostics were not a JSON object.');
            }
            $snapshot['elapsed_seconds'] = round((hrtime(true) - $start) / 1_000_000_000, 3);
            fwrite($file, json_encode($snapshot, JSON_THROW_ON_ERROR) . "\n");
            fflush($file);
            if (time_nanosleep($interval, 0) === false) {
                throw new RuntimeException('Internal telemetry sample interval could not be observed.');
            }
        }
    } finally {
        fclose($file);
    }
}

$scriptFilename = $_SERVER['SCRIPT_FILENAME'] ?? null;
if (is_string($scriptFilename) && realpath($scriptFilename) === __FILE__) {
    if (!isset($argc, $argv)) {
        throw new \RuntimeException('CLI arguments are unavailable.');
    }
    if ($argc === 6 && $argv[1] === 'sample') {
        soakSampleRuntimeHttp((int) $argv[2], (int) $argv[3], $argv[4], (int) $argv[5]);
    } elseif ($argc === 4 && $argv[1] === 'analyze') {
        $result = soakAnalyzeRuntimeSnapshots(soakReadRuntimeSnapshots($argv[2]));
        file_put_contents(runwireBenchmarkOutputPath($argv[3]), json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL);
        if (!$result['passed']) {
            throw new RuntimeException('Runwire worker GC/OPcache/heap soak analysis failed.');
        }
    } else {
        throw new InvalidArgumentException('Usage: php soak_runtime_telemetry.php sample <port> <pid> <jsonl> <seconds> | analyze <jsonl> <json>');
    }
}
