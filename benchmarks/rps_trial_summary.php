<?php

declare(strict_types=1);

/** @param list<float> $values */
function trialMedian(array $values): float
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
function trialCv(array $values): float
{
    $count = count($values);
    if ($count < 2) {
        return 0.0;
    }

    $mean = array_sum($values) / $count;
    if ($mean <= 0.0) {
        return 0.0;
    }

    $squares = 0.0;
    foreach ($values as $value) {
        $squares += ($value - $mean) ** 2;
    }

    return sqrt($squares / ($count - 1)) / $mean * 100.0;
}

$paths = array_slice($argv, 1);
if (count($paths) < 5) {
    throw new RuntimeException('Repeated throughput summary requires at least five trials.');
}

$records = [];
foreach ($paths as $path) {
    $decoded = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    if (!is_array($decoded) || ($decoded['correctness_passed'] ?? false) !== true) {
        throw new RuntimeException(sprintf('Invalid or failed throughput evidence: %s', $path));
    }
    if (!isset($decoded['label'], $decoded['throughput_rps'], $decoded['elapsed_ms'])) {
        throw new RuntimeException(sprintf('Incomplete throughput evidence: %s', $path));
    }
    $records[] = $decoded;
}

$label = (string) $records[0]['label'];
foreach ($records as $record) {
    if ((string) $record['label'] !== $label) {
        throw new RuntimeException('Throughput evidence labels do not match.');
    }
}

$rps = array_map(static fn(array $record): float => (float) $record['throughput_rps'], $records);
$elapsed = array_map(static fn(array $record): float => (float) $record['elapsed_ms'], $records);
$mean = array_sum($rps) / count($rps);

$result = [
    'label' => $label,
    'trials' => count($records),
    'median_rps' => round(trialMedian($rps), 3),
    'mean_rps' => round($mean, 3),
    'rps_cv_percent' => round(trialCv($rps), 3),
    'min_rps' => round(min($rps), 3),
    'max_rps' => round(max($rps), 3),
    'median_elapsed_ms' => round(trialMedian($elapsed), 3),
    'correctness_passed' => true,
];

fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
