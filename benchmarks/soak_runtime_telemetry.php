<?php

declare(strict_types=1);

/** @return array<string, int|bool> */
function soakRuntimeSnapshot(): array
{
    $gc = gc_status();
    $opcache = function_exists('opcache_get_status') ? opcache_get_status(false) : false;

    return [
        'allocated_bytes' => memory_get_usage(true),
        'peak_allocated_bytes' => memory_get_peak_usage(true),
        'gc_enabled' => gc_enabled(),
        'gc_runs' => (int) ($gc['runs'] ?? 0),
        'gc_collected' => (int) ($gc['collected'] ?? 0),
        'gc_roots' => (int) ($gc['roots'] ?? 0),
        'opcache_enabled' => is_array($opcache) && ($opcache['opcache_enabled'] ?? false) === true,
        'opcache_used_bytes' => is_array($opcache) ? (int) ($opcache['memory_usage']['used_memory'] ?? 0) : 0,
        'opcache_cached_scripts' => is_array($opcache) ? (int) ($opcache['opcache_statistics']['num_cached_scripts'] ?? 0) : 0,
    ];
}

/** @param list<array<string, int|float|bool>> $samples @return array<string, int|float|bool|string> */
function soakAnalyzeRuntimeSnapshots(array $samples, float $minimumDurationSeconds = 1_740.0): array
{
    if ($minimumDurationSeconds <= 0 || !is_finite($minimumDurationSeconds)) {
        throw new InvalidArgumentException('Internal soak minimum duration must be positive and finite.');
    }

    $duration = $samples === [] ? 0.0 : (float) $samples[count($samples) - 1]['elapsed_seconds'];
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

        if (!is_finite($elapsed) || $elapsed <= $previousElapsed
            || $allocated <= 0 || $used < 0 || $gcRoots < 0 || $gcRuns < $previousGcRuns
            || $gcCollected < $previousGcCollected
            || ($sample['gc_enabled'] ?? false) !== true
            || ($sample['opcache_enabled'] ?? false) !== true) {
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

    $enough = $valid && $duration >= $minimumDurationSeconds
        && count($samples) >= 100 && count($early) >= 8 && count($late) >= 8;
    $median = static function (array $rows, string $key): float {
        if ($rows === []) {
            return 0.0;
        }
        $values = array_map(static fn(array $row): int => (int) $row[$key], $rows);
        sort($values, SORT_NUMERIC);
        $at = intdiv(count($values), 2);

        return count($values) % 2 === 0
            ? ($values[$at - 1] + $values[$at]) / 2
            : (float) $values[$at];
    };

    $earlyHeap = $median($early, 'allocated_bytes');
    $lateHeap = $median($late, 'allocated_bytes');
    $earlyRoots = $median($early, 'gc_roots');
    $lateRoots = $median($late, 'gc_roots');
    $earlyCache = $median($early, 'opcache_used_bytes');
    $lateCache = $median($late, 'opcache_used_bytes');
    $passed = $enough
        && $lateHeap <= $earlyHeap * 1.15 + 16_777_216
        && $lateRoots <= $earlyRoots + 2_048
        && $lateCache <= $earlyCache + 8_388_608;

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

/** @return list<array<string, int|float|bool>> */
function soakReadRuntimeSnapshots(string $path): array
{
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
            $records[] = $record;
        }
    } finally {
        fclose($file);
    }

    return $records;
}

function soakSampleRuntimeHttp(int $port, int $rootPid, string $path, int $interval): void
{
    if ($port < 1 || $port > 65_535 || $rootPid < 1 || $interval < 1 || $interval > 60) {
        throw new InvalidArgumentException('Invalid internal telemetry sampler arguments.');
    }

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
            time_nanosleep($interval, 0);
        }
    } finally {
        fclose($file);
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    if ($argc === 6 && $argv[1] === 'sample') {
        soakSampleRuntimeHttp((int) $argv[2], (int) $argv[3], $argv[4], (int) $argv[5]);
    } elseif ($argc === 4 && $argv[1] === 'analyze') {
        $result = soakAnalyzeRuntimeSnapshots(soakReadRuntimeSnapshots($argv[2]));
        file_put_contents($argv[3], json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL);
        if (!$result['passed']) {
            throw new RuntimeException('Runwire worker GC/OPcache/heap soak analysis failed.');
        }
    } else {
        throw new InvalidArgumentException('Usage: php soak_runtime_telemetry.php sample <port> <pid> <jsonl> <seconds> | analyze <jsonl> <json>');
    }
}
