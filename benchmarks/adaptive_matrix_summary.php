<?php

declare(strict_types=1);

require_once __DIR__ . '/cli_local_paths.php';

/**
 * @param list<float|int> $values
 * @throws \RuntimeException
 */
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
        : ((float) $values[$middle - 1] + (float) $values[$middle]) / 2.0;
}

/**
 * @return array<string, mixed>
 * @throws \RuntimeException
 */
function adaptiveMatrixLoad(string $path): array
{
    if (!is_file($path) || !is_readable($path)) {
        throw new RuntimeException('Unable to read adaptive matrix evidence: ' . $path);
    }
    $path = runwireBenchmarkReadablePath($path);
    $contents = file_get_contents($path);
    if (!is_string($contents)) {
        throw new RuntimeException('Unable to read adaptive matrix evidence: ' . $path);
    }
    $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($decoded)) {
        throw new RuntimeException('Adaptive matrix evidence must decode to an object.');
    }

    $record = [];
    foreach ($decoded as $name => $value) {
        if (!is_string($name)) {
            throw new RuntimeException('Adaptive matrix evidence requires string field names.');
        }
        $record[$name] = $value;
    }

    return $record;
}

/**
 * @return array<string, mixed>
 * @throws \RuntimeException
 */
function adaptiveMatrixPhase(mixed $source, string $name): array
{
    if (!is_array($source)) {
        throw new RuntimeException('Adaptive comparison has missing or invalid phase: ' . $name);
    }

    $phase = [];
    foreach ($source as $key => $value) {
        if (!is_string($key)) {
            throw new RuntimeException('Adaptive comparison phase requires string metric names.');
        }
        $phase[$key] = $value;
    }

    return $phase;
}

/**
 * @param array<string, mixed> $phase
 * @throws \RuntimeException
 */
function adaptiveMatrixNumeric(array $phase, string $name): int|float
{
    $value = $phase[$name] ?? null;
    if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value) || $value < 0) {
        throw new RuntimeException('Adaptive evidence needs a finite non-negative metric: ' . $name);
    }

    return $value;
}

/**
 * @param array<string,mixed> $phase
 * @throws RuntimeException
 */
function adaptiveMatrixMetric(array $phase, string $name): float
{
    return (float) adaptiveMatrixNumeric($phase, $name);
}

/**
 * @param list<float> $values
 * @throws \RuntimeException
 */
function adaptiveMatrixCv(array $values): float
{
    $count = count($values);
    if ($count < 2) {
        throw new RuntimeException('Adaptive variance needs repeated positive throughput samples.');
    }
    $mean = array_sum($values) / (float) $count;
    if ($mean <= 0) {
        throw new RuntimeException('Adaptive variance needs repeated positive throughput samples.');
    }
    $squares = array_sum(array_map(static fn(float $value): float => ($value - $mean) ** 2.0, $values));

    return sqrt($squares / (float) (count($values) - 1)) / $mean * 100.0;
}

/**
 * @param array<string,mixed> $reference
 * @return list<string>
 * @throws RuntimeException
 */
function adaptiveMatrixMetadata(array $reference): array
{
    $mode = $reference['mode'] ?? null;
    $metadata = ['protocol', 'mode', 'workload', 'payload_bytes', 'runtime_build', 'environment', 'warmup_seconds'];
    if (isset($reference['case'])) {
        $metadata[] = 'case';
    }
    foreach ($metadata as $name) {
        if (!isset($reference[$name]) || in_array($reference[$name], ['', 'unrecorded'], true)) {
            throw new RuntimeException('Adaptive evidence is missing metadata: ' . $name);
        }
    }
    if (!in_array($mode, ['auto', 'fixed', 'latency', 'throughput'], true)) {
        throw new RuntimeException('Adaptive evidence has an invalid policy mode.');
    }
    adaptiveMatrixMetric($reference, 'warmup_seconds');

    return $metadata;
}

/**
 * @param list<array<string,mixed>> $records
 * @throws RuntimeException
 */
function adaptiveMatrixValidateTrials(array $records): void
{
    $ids = [];
    foreach ($records as $record) {
        $trial = $record['trial'] ?? null;
        if (!is_int($trial) && !is_string($trial)) {
            throw new RuntimeException('Promotion evidence requires integer or string trial IDs.');
        }
        $ids[] = $trial;
    }
    if (count(array_unique($ids)) !== count($records)) {
        throw new RuntimeException('Promotion evidence requires distinct trial IDs.');
    }
}

/**
 * @param array<string,mixed> $record
 * @param list<string> $names
 * @return array<string,array<string,mixed>>
 * @throws RuntimeException
 */
function adaptiveMatrixNamedPhases(array $record, array $names): array
{
    $raw = $record['phases'] ?? null;
    if (!is_array($raw) || count($raw) !== count($names)) {
        throw new RuntimeException('Adaptive evidence has missing or unexpected phases.');
    }
    $phases = [];
    foreach ($names as $name) {
        $phases[$name] = adaptiveMatrixPhase($raw[$name] ?? null, $name);
    }

    return $phases;
}

/**
 * @param array<string,mixed> $phase
 * @param array<string,mixed> $reference
 * @param list<string> $metrics
 * @throws RuntimeException
 */
function adaptiveMatrixValidatePhase(array $phase, array $reference, string $name, array $metrics): void
{
    if (($phase['correctness_passed'] ?? false) !== true
        || adaptiveMatrixMetric($phase, 'requests') <= 0
        || adaptiveMatrixMetric($phase, 'errors') !== 0.0
        || adaptiveMatrixMetric($phase, 'throughput_rps') <= 0
        || adaptiveMatrixMetric($phase, 'fairness_ratio') < 1
        || adaptiveMatrixMetric($phase, 'duration_seconds') <= 0) {
        throw new RuntimeException('Adaptive evidence contains an invalid or failed phase: ' . $name);
    }
    foreach (['concurrency', 'requested_duration_seconds'] as $field) {
        if (adaptiveMatrixMetric($phase, $field) <= 0 || $phase[$field] !== ($reference[$field] ?? null)) {
            throw new RuntimeException('Adaptive phase configuration mismatch: ' . $field);
        }
    }
    foreach ($metrics as $metric) {
        adaptiveMatrixMetric($phase, $metric);
    }
}

/**
 * @param array<string,mixed> $record
 * @param array<string,mixed> $reference
 * @param list<string> $metadata
 * @param list<string> $names
 * @param list<string> $metrics
 * @param array<string,array<string,mixed>> $referencePhases
 * @return array<string,array<string,mixed>>
 * @throws RuntimeException
 */
function adaptiveMatrixValidateTrial(array $record, array $reference, array $metadata, array $names, array $metrics, array $referencePhases): array
{
    foreach ($metadata as $name) {
        if (($record[$name] ?? null) !== ($reference[$name] ?? null)) {
            throw new RuntimeException('Adaptive evidence metadata mismatch: ' . $name);
        }
    }
    if (($record['correctness_passed'] ?? false) !== true) {
        throw new RuntimeException('Adaptive evidence failed correctness.');
    }
    $phases = adaptiveMatrixNamedPhases($record, $names);
    foreach ($phases as $name => $phase) {
        $referencePhase = $referencePhases[$name] ?? throw new RuntimeException('Missing reference phase: ' . $name);
        adaptiveMatrixValidatePhase($phase, $referencePhase, $name, $metrics);
    }

    return $phases;
}

/**
 * @param list<array<string,array<string,mixed>>> $records
 * @param array<string,mixed> $reference
 * @param list<string> $metrics
 * @return array<string,int|float>
 * @throws RuntimeException
 */
function adaptiveMatrixAggregatePhase(array $records, array $reference, string $name, array $metrics): array
{
    if ($records === []) {
        throw new RuntimeException('Cannot aggregate empty phase evidence.');
    }

    $phase = [];
    foreach ($metrics as $metric) {
        $values = array_map(
            static fn(array $rows): float => adaptiveMatrixMetric(
                $rows[$name] ?? throw new RuntimeException('Missing validated phase: ' . $name),
                $metric,
            ),
            $records,
        );
        $median = adaptiveMatrixMedian($values);
        $phase[$metric] = $median;
        if ($metric === 'client_cpu_percent') {
            $phase['client_cpu_percent_max'] = max($values);
        }
        if ($metric === 'throughput_rps') {
            $phase['rps_cv_percent'] = adaptiveMatrixCv($values);
            $phase['throughput_rpm'] = $median * 60.0;
        }
    }
    $phase['duration_seconds_min'] = min(array_map(
        static fn(array $rows): float => adaptiveMatrixMetric(
            $rows[$name] ?? throw new RuntimeException('Missing validated phase: ' . $name),
            'duration_seconds',
        ),
        $records,
    ));
    foreach (['concurrency', 'requested_duration_seconds'] as $field) {
        $phase[$field] = adaptiveMatrixNumeric($reference, $field);
    }

    return $phase;
}

/**
 * @param array<int,array<string,mixed>> $records
 * @return array{protocol:mixed,mode:mixed,workload:mixed,payload_bytes:mixed,runtime_build:mixed,environment:mixed,warmup_seconds:mixed,case?:mixed,trials:int,phases:array<string,array<string,int|float>>,correctness_passed:bool,scope:string}
 * @throws RuntimeException
 */
function adaptiveMatrixSummarize(array $records): array
{
    if (!array_is_list($records) || count($records) < 5) {
        throw new RuntimeException('Adaptive matrix summary requires at least five trials.');
    }
    $reference = $records[0];
    $metadata = adaptiveMatrixMetadata($reference);
    $phaseNames = ['low_before', 'transition_up', 'high', 'transition_down', 'low_after'];
    $metrics = ['throughput_rps', 'p50_ms', 'p95_ms', 'p99_ms', 'cpu_percent', 'rss_peak_bytes', 'fairness_ratio'];
    if (isset($reference['case'])) {
        $phaseNames[] = 'medium';
        $metrics[] = 'client_cpu_percent';
        adaptiveMatrixValidateTrials($records);
    }
    $referencePhases = adaptiveMatrixNamedPhases($reference, $phaseNames);
    $validated = [];
    foreach ($records as $record) {
        $validated[] = adaptiveMatrixValidateTrial($record, $reference, $metadata, $phaseNames, $metrics, $referencePhases);
    }
    $phases = [];
    foreach ($phaseNames as $name) {
        $referencePhase = $referencePhases[$name] ?? throw new RuntimeException('Missing reference phase: ' . $name);
        $phases[$name] = adaptiveMatrixAggregatePhase($validated, $referencePhase, $name, $metrics);
    }
    $result = [
        'protocol' => $reference['protocol'],
        'mode' => $reference['mode'],
        'workload' => $reference['workload'],
        'payload_bytes' => $reference['payload_bytes'],
        'runtime_build' => $reference['runtime_build'],
        'environment' => $reference['environment'],
        'warmup_seconds' => $reference['warmup_seconds'],
        'trials' => count($records),
        'phases' => $phases,
        'correctness_passed' => true,
        'scope' => 'single-workload',
    ];
    if (isset($reference['case'])) {
        $result['case'] = $reference['case'];
    }

    return $result;
}

if (realpath($argv[0] ?? '') === __FILE__) {
    if (!isset($argc, $argv)) {
        throw new \RuntimeException('CLI arguments are unavailable.');
    }
    $records = array_map(adaptiveMatrixLoad(...), array_slice($argv, 1));
    fwrite(STDOUT, json_encode(adaptiveMatrixSummarize($records), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
}
