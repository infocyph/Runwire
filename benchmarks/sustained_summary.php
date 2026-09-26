<?php

declare(strict_types=1);

require __DIR__ . '/comparative_evidence.php';

/** @param list<float> $values */
function sustainedMedian(array $values): float
{
    sort($values, SORT_NUMERIC);
    $count = count($values);
    if ($count === 0) {
        throw new RuntimeException('Cannot summarize an empty trial set.');
    }
    $middle = intdiv($count, 2);

    return $count % 2 === 1
        ? $values[$middle]
        : ($values[$middle - 1] + $values[$middle]) / 2;
}

/** @param list<float> $values */
function sustainedCoefficientOfVariation(array $values): float
{
    $count = count($values);
    if ($count < 2) {
        return 0.0;
    }

    $mean = array_sum($values) / $count;
    if ($mean <= 0.0) {
        return 0.0;
    }

    $sumSquares = 0.0;
    foreach ($values as $value) {
        $sumSquares += ($value - $mean) ** 2;
    }

    return sqrt($sumSquares / ($count - 1)) / $mean * 100.0;
}

$paths = array_slice($argv, 1);
if (count($paths) < 5) {
    throw new RuntimeException('Sustained evidence requires at least five repeated trials.');
}

/** @var list<array<string, mixed>> $records */
$records = array_map(loadEvidence(...), $paths);
assertComparable($records);

$reference = $records[0];
foreach ($records as $record) {
    foreach (['runtime', 'runtime_version', 'runtime_build'] as $field) {
        if ($record[$field] !== $reference[$field]) {
            throw new RuntimeException(sprintf('Sustained evidence mismatch for "%s".', $field));
        }
    }
    if (
        $record['correctness_passed'] !== true
        || (int) $record['requests_total'] === 0
        || (int) $record['completed_requests'] !== (int) $record['requests_total']
        || (int) $record['successful_requests'] !== (int) $record['requests_total']
        || (int) $record['errors_total'] !== 0
        || (int) $record['timeouts_total'] !== 0
        || (int) $record['validation_failures'] !== 0
    ) {
        throw new RuntimeException('Sustained evidence contains an incomplete, failed, timed-out, or invalid response.');
    }
}

$rps = array_map(static fn(array $record): float => (float) $record['throughput_rps'], $records);
$p95 = array_map(static fn(array $record): float => (float) $record['latency_ms']['p95'], $records);
$p99 = array_map(static fn(array $record): float => (float) $record['latency_ms']['p99'], $records);
$rss = array_map(static fn(array $record): int => (int) $record['rss_peak_bytes'], $records);
$cpu = array_map(static fn(array $record): float => (float) $record['cpu_percent'], $records);
$medianRps = sustainedMedian($rps);

$summary = [
    'runtime' => $reference['runtime'],
    'runtime_version' => $reference['runtime_version'],
    'runtime_build' => $reference['runtime_build'],
    'protocol' => $reference['protocol'],
    'workload' => $reference['workload'],
    'php_version' => $reference['php_version'],
    'hardware_id' => $reference['hardware_id'],
    'instrumentation' => $reference['instrumentation'],
    'trials' => count($records),
    'duration_seconds_per_trial' => $reference['duration_seconds'],
    'concurrency' => $reference['concurrency'],
    'median_successful_rps' => round($medianRps, 3),
    'median_successful_rpm' => round($medianRps * 60.0, 3),
    'rps_cv_percent' => round(sustainedCoefficientOfVariation($rps), 3),
    'median_p95_ms' => round(sustainedMedian($p95), 3),
    'median_p99_ms' => round(sustainedMedian($p99), 3),
    'median_cpu_percent' => round(sustainedMedian($cpu), 3),
    'peak_rss_bytes' => max($rss),
    'correctness_passed' => true,
];

fwrite(STDOUT, json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
