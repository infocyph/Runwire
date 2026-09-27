<?php

declare(strict_types=1);

/** @return array<string, mixed> */
function adaptiveCompareLoad(string $path): array
{
    $contents = file_get_contents($path);
    if (!is_string($contents)) {
        throw new RuntimeException('Unable to read adaptive comparison input: ' . $path);
    }
    $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($decoded)) {
        throw new RuntimeException('Adaptive comparison input must decode to an object.');
    }

    return $decoded;
}

if ($argc !== 3) {
    throw new InvalidArgumentException('Usage: php adaptive_matrix_compare.php <auto-summary> <fixed-summary>');
}

$auto = adaptiveCompareLoad($argv[1]);
$fixed = adaptiveCompareLoad($argv[2]);
if (($auto['protocol'] ?? null) !== ($fixed['protocol'] ?? null)) {
    throw new RuntimeException('Adaptive comparison protocol mismatch.');
}
if (($auto['mode'] ?? null) !== 'auto' || ($fixed['mode'] ?? null) !== 'fixed') {
    throw new RuntimeException('Adaptive comparison requires AUTO then FIXED summaries.');
}
if (($auto['correctness_passed'] ?? false) !== true || ($fixed['correctness_passed'] ?? false) !== true) {
    throw new RuntimeException('Adaptive comparison requires correct summaries.');
}

$phaseNames = ['low_before', 'transition_up', 'high', 'transition_down', 'low_after'];
$failures = [];
$deltas = [];
foreach ($phaseNames as $phase) {
    $a = $auto['phases'][$phase];
    $f = $fixed['phases'][$phase];

    $throughputRatio = (float) $a['throughput_rps'] / max(0.001, (float) $f['throughput_rps']);
    $autoP95 = (float) $a['p95_ms'];
    $fixedP95 = (float) $f['p95_ms'];
    $autoP99 = (float) $a['p99_ms'];
    $fixedP99 = (float) $f['p99_ms'];
    $p95Ratio = $autoP95 / max(0.001, $fixedP95);
    $p99Ratio = $autoP99 / max(0.001, $fixedP99);
    $p95AbsoluteDelta = $autoP95 - $fixedP95;
    $p99AbsoluteDelta = $autoP99 - $fixedP99;
    $cpuLimit = ((float) $f['cpu_percent'] * 1.15) + 5.0;
    $rssLimit = ((float) $f['rss_peak_bytes'] * 1.10) + 4_194_304;
    $fairnessLimit = max(1.25, (float) $f['fairness_ratio'] * 1.25);

    $deltas[$phase] = [
        'throughput_ratio' => round($throughputRatio, 4),
        'p95_ratio' => round($p95Ratio, 4),
        'p99_ratio' => round($p99Ratio, 4),
        'p95_absolute_delta_ms' => round($p95AbsoluteDelta, 4),
        'p99_absolute_delta_ms' => round($p99AbsoluteDelta, 4),
        'auto_cpu_percent' => (float) $a['cpu_percent'],
        'fixed_cpu_percent' => (float) $f['cpu_percent'],
        'auto_rss_peak_bytes' => (int) $a['rss_peak_bytes'],
        'fixed_rss_peak_bytes' => (int) $f['rss_peak_bytes'],
        'auto_fairness_ratio' => (float) $a['fairness_ratio'],
        'fixed_fairness_ratio' => (float) $f['fairness_ratio'],
    ];

    if ($throughputRatio < 0.95) {
        $failures[] = $phase . ': AUTO throughput regressed more than 5%.';
    }
    if ($p95Ratio > 1.15 && $p95AbsoluteDelta > 0.5) {
        $failures[] = $phase . ': AUTO p95 regressed more than 15% and 0.5 ms.';
    }
    if ($p99Ratio > 1.15 && $p99AbsoluteDelta > 0.5) {
        $failures[] = $phase . ': AUTO p99 regressed more than 15% and 0.5 ms.';
    }
    if ((float) $a['cpu_percent'] > $cpuLimit) {
        $failures[] = $phase . ': AUTO CPU exceeded the regression budget.';
    }
    if ((float) $a['rss_peak_bytes'] > $rssLimit) {
        $failures[] = $phase . ': AUTO RSS exceeded the regression budget.';
    }
    if ((float) $a['fairness_ratio'] > $fairnessLimit) {
        $failures[] = $phase . ': AUTO fairness exceeded the regression budget.';
    }
}

$result = [
    'protocol' => $auto['protocol'],
    'trials' => min((int) $auto['trials'], (int) $fixed['trials']),
    'budgets' => [
        'throughput_floor_ratio' => 0.95,
        'latency_ceiling_ratio' => 1.15,
        'latency_absolute_tolerance_ms' => 0.5,
        'latency_rule' => 'fail only when relative and absolute latency budgets are both exceeded',
        'cpu_ceiling' => 'fixed * 1.15 + 5 percentage points',
        'rss_ceiling' => 'fixed * 1.10 + 4 MiB',
        'fairness_ceiling' => 'max(1.25, fixed * 1.25)',
    ],
    'phases' => $deltas,
    'failures' => $failures,
    'passed' => $failures === [],
];

fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
if ($failures !== []) {
    throw new RuntimeException('Adaptive AUTO vs FIXED regression budget failed.');
}
