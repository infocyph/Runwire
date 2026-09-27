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

$paths = array_slice($argv, 1);
if (count($paths) < 5) {
    throw new RuntimeException('Adaptive matrix summary requires at least five trials.');
}

$records = array_map(adaptiveMatrixLoad(...), $paths);
$reference = $records[0];
$protocol = (string) ($reference['protocol'] ?? '');
$mode = (string) ($reference['mode'] ?? '');
$phaseNames = ['low_before', 'transition_up', 'high', 'transition_down', 'low_after'];
if ($protocol === '' || !in_array($mode, ['auto', 'fixed'], true)) {
    throw new RuntimeException('Adaptive matrix evidence has invalid protocol or mode metadata.');
}

foreach ($records as $record) {
    if (($record['protocol'] ?? null) !== $protocol || ($record['mode'] ?? null) !== $mode) {
        throw new RuntimeException('Adaptive matrix evidence metadata mismatch.');
    }
    if (($record['correctness_passed'] ?? false) !== true) {
        throw new RuntimeException('Adaptive matrix evidence failed correctness.');
    }
    foreach ($phaseNames as $phase) {
        if (($record['phases'][$phase]['correctness_passed'] ?? false) !== true) {
            throw new RuntimeException('Adaptive matrix phase failed correctness: ' . $phase);
        }
    }
}

$metrics = [
    'throughput_rps',
    'p50_ms',
    'p95_ms',
    'p99_ms',
    'cpu_percent',
    'rss_peak_bytes',
    'fairness_ratio',
];
$phases = [];
foreach ($phaseNames as $phase) {
    $phases[$phase] = [];
    foreach ($metrics as $metric) {
        $values = array_map(
            static fn(array $record): float => (float) $record['phases'][$phase][$metric],
            $records,
        );
        $phases[$phase][$metric] = round(adaptiveMatrixMedian($values), 4);
    }
}

$result = [
    'protocol' => $protocol,
    'mode' => $mode,
    'trials' => count($records),
    'phases' => $phases,
    'transition_latency_ms' => [
        'up_p95' => $phases['transition_up']['p95_ms'],
        'down_p95' => $phases['transition_down']['p95_ms'],
    ],
    'correctness_passed' => true,
];

fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
