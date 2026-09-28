<?php

declare(strict_types=1);

/** @param list<float|int> $values */
function adaptiveMatrixMedian(array $values): float
{
    if ($values === []) {
        throw new RuntimeException('Adaptive matrix summary cannot use an empty metric set.');
    }
    sort($values, SORT_NUMERIC);
    $count = count($values);
    $middle = intdiv($count, 2);

    return $count % 2 === 1
        ? (float) $values[$middle]
        : ((float) $values[$middle - 1] + (float) $values[$middle]) / 2;
}

/** @return array<string, mixed> */
function adaptiveMatrixLoad(string $path): array
{
    $contents = file_get_contents($path);
    if (!is_string($contents)) {
        throw new RuntimeException('Unable to read adaptive matrix evidence: ' . $path);
    }
    $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($decoded)) {
        throw new RuntimeException('Adaptive matrix evidence must decode to an object.');
    }

    return $decoded;
}

/** @param array<string, mixed> $phase */
function adaptiveMatrixMetric(array $phase, string $name): float
{
    $value = $phase[$name] ?? null;
    if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value) || $value < 0) {
        throw new RuntimeException('Adaptive evidence needs a finite non-negative metric: ' . $name);
    }

    return (float) $value;
}

/** @param list<float> $values */
function adaptiveMatrixCv(array $values): float
{
    $mean = array_sum($values) / count($values);
    if ($mean <= 0 || count($values) < 2) {
        throw new RuntimeException('Adaptive variance needs repeated positive throughput samples.');
    }
    $squares = array_sum(array_map(static fn(float $value): float => ($value - $mean) ** 2, $values));

    return sqrt($squares / (count($values) - 1)) / $mean * 100;
}

/** @param list<array<string, mixed>> $records @return array<string, mixed> */
function adaptiveMatrixSummarize(array $records): array
{
    if (count($records) < 5) {
        throw new RuntimeException('Adaptive matrix summary requires at least five trials.');
    }
    $reference = $records[0];
    $metadata = ['protocol', 'mode', 'workload', 'payload_bytes', 'runtime_build', 'environment', 'warmup_seconds'];
    if (isset($reference['case'])) {
        $metadata[] = 'case';
    }
    foreach ($metadata as $name) {
        if (!isset($reference[$name]) || in_array($reference[$name], ['', 'unrecorded'], true)) {
            throw new RuntimeException('Adaptive evidence is missing metadata: ' . $name);
        }
    }
    if (!in_array($reference['mode'], ['auto', 'fixed', 'latency', 'throughput'], true)) {
        throw new RuntimeException('Adaptive evidence has an invalid policy mode.');
    }
    adaptiveMatrixMetric($reference, 'warmup_seconds');
    $phaseNames = ['low_before', 'transition_up', 'high', 'transition_down', 'low_after'];
    if (isset($reference['case'])) {
        $phaseNames[] = 'medium';
    }
    $metrics = ['throughput_rps', 'p50_ms', 'p95_ms', 'p99_ms', 'cpu_percent', 'rss_peak_bytes', 'fairness_ratio'];
    if (isset($reference['case'])) {
        $metrics[] = 'client_cpu_percent';
        $trialIds = array_column($records, 'trial');
        if (count($trialIds) !== count($records) || count(array_unique($trialIds)) !== count($records)) {
            throw new RuntimeException('Promotion evidence requires distinct trial IDs.');
        }
    }
    foreach ($records as $record) {
        foreach ($metadata as $name) {
            if (($record[$name] ?? null) !== $reference[$name]) {
                throw new RuntimeException('Adaptive evidence metadata mismatch: ' . $name);
            }
        }
        if (($record['correctness_passed'] ?? false) !== true) {
            throw new RuntimeException('Adaptive evidence failed correctness.');
        }
        if (count($record['phases'] ?? []) !== count($phaseNames)) {
            throw new RuntimeException('Adaptive evidence has missing or unexpected phases.');
        }
        foreach ($phaseNames as $name) {
            $phase = $record['phases'][$name] ?? [];
            if (($phase['correctness_passed'] ?? false) !== true
                || adaptiveMatrixMetric($phase, 'requests') <= 0
                || adaptiveMatrixMetric($phase, 'errors') !== 0.0
                || adaptiveMatrixMetric($phase, 'throughput_rps') <= 0
                || adaptiveMatrixMetric($phase, 'fairness_ratio') < 1
                || adaptiveMatrixMetric($phase, 'duration_seconds') <= 0) {
                throw new RuntimeException('Adaptive evidence contains an invalid or failed phase: ' . $name);
            }
            foreach (['concurrency', 'requested_duration_seconds'] as $field) {
                if (adaptiveMatrixMetric($phase, $field) <= 0
                    || $phase[$field] !== $reference['phases'][$name][$field]) {
                    throw new RuntimeException('Adaptive phase configuration mismatch: ' . $field);
                }
            }
            foreach ($metrics as $metric) {
                adaptiveMatrixMetric($phase, $metric);
            }
        }
    }

    $phases = [];
    foreach ($phaseNames as $name) {
        foreach ($metrics as $metric) {
            $values = array_map(static fn(array $record): float => (float) $record['phases'][$name][$metric], $records);
            $phases[$name][$metric] = adaptiveMatrixMedian($values);
            if ($metric === 'client_cpu_percent') {
                $phases[$name]['client_cpu_percent_max'] = max($values);
            }
            if ($metric === 'throughput_rps') {
                $phases[$name]['rps_cv_percent'] = adaptiveMatrixCv($values);
                $phases[$name]['throughput_rpm'] = $phases[$name][$metric] * 60;
            }
        }
        $phases[$name]['duration_seconds_min'] = min(array_map(static fn(array $record): float => (float) $record['phases'][$name]['duration_seconds'], $records));
        foreach (['concurrency', 'requested_duration_seconds'] as $field) {
            $phases[$name][$field] = $reference['phases'][$name][$field];
        }
    }

    return array_intersect_key($reference, array_flip($metadata)) + [
        'trials' => count($records),
        'phases' => $phases,
        'correctness_passed' => true,
        'scope' => 'single-workload',
    ];
}

if (realpath($argv[0] ?? '') === __FILE__) {
    $records = array_map(adaptiveMatrixLoad(...), array_slice($argv, 1));
    fwrite(STDOUT, json_encode(adaptiveMatrixSummarize($records), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
}
