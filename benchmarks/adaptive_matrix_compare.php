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

require_once __DIR__ . '/adaptive_matrix_summary.php';

/** @param array<string, mixed> $auto @param array<string, mixed> $fixed @return array<string, mixed> */
function adaptiveMatrixCompare(array $auto, array $fixed, bool $diagnostic = false, string $baselineMode = 'fixed'): array
{
    if (!in_array($baselineMode, ['fixed', 'latency', 'throughput'], true)) {
        throw new RuntimeException('Invalid adaptive comparison baseline mode.');
    }
    if (($auto['protocol'] ?? null) !== ($fixed['protocol'] ?? null)) {
        throw new RuntimeException('Adaptive comparison protocol mismatch.');
    }
    if (($auto['mode'] ?? null) !== 'auto' || ($fixed['mode'] ?? null) !== $baselineMode) {
        throw new RuntimeException('Adaptive comparison requires AUTO then the selected baseline summaries.');
    }
    if (($auto['correctness_passed'] ?? false) !== true || ($fixed['correctness_passed'] ?? false) !== true) {
        throw new RuntimeException('Adaptive comparison requires correct summaries.');
    }

    foreach (['workload', 'payload_bytes', 'runtime_build', 'environment', 'warmup_seconds'] as $name) {
        if (!isset($auto[$name], $fixed[$name]) || $auto[$name] !== $fixed[$name]) {
            throw new RuntimeException('Adaptive comparison metadata mismatch: ' . $name);
        }
    }
    if (($auto['trials'] ?? 0) < 5 || ($fixed['trials'] ?? 0) < 5) {
        throw new RuntimeException('Adaptive comparison requires at least five trials per mode.');
    }
    if (($auto['case'] ?? null) !== ($fixed['case'] ?? null)) {
        throw new RuntimeException('Adaptive comparison case mismatch.');
    }
    $evidenceFailures = [];
    if (adaptiveMatrixMetric($auto, 'warmup_seconds') < 30) {
        $evidenceFailures[] = 'Sustained evidence requires at least 30 seconds of warm-up.';
    }
    $phaseNames = ['low_before', 'transition_up', 'high', 'transition_down', 'low_after'];
    if (isset($auto['case'])) {
        $phaseNames[] = 'medium';
    }
    $failures = [];
    $deltas = [];
    foreach ($phaseNames as $phaseName) {
        $a = $auto['phases'][$phaseName];
        $f = $fixed['phases'][$phaseName];

        foreach (['concurrency', 'requested_duration_seconds'] as $name) {
            if (adaptiveMatrixMetric($a, $name) <= 0 || $a[$name] !== ($f[$name] ?? null)) {
                throw new RuntimeException('Adaptive comparison phase mismatch: ' . $name);
            }
        }
        foreach ([$a, $f] as $phase) {
            if (isset($auto['case']) && adaptiveMatrixMetric($phase, 'client_cpu_percent_max') >= 85) {
                $evidenceFailures[] = 'Load generator CPU may limit throughput: ' . $phaseName;
            }
            foreach (['throughput_rps', 'p95_ms', 'p99_ms', 'cpu_percent', 'rss_peak_bytes', 'fairness_ratio', 'rps_cv_percent', 'duration_seconds_min'] as $metric) {
                adaptiveMatrixMetric($phase, $metric);
            }
            if ($phase['throughput_rps'] <= 0 || $phase['fairness_ratio'] < 1) {
                throw new RuntimeException('Adaptive comparison has invalid throughput or fairness.');
            }
            if ($phase['rps_cv_percent'] >= 2.5) {
                $evidenceFailures[] = 'Throughput CV must be below 2.5%: ' . $phaseName;
            }
            if (!str_starts_with($phaseName, 'transition_') && ($phase['duration_seconds_min'] < 180 || $phase['requested_duration_seconds'] < 180)) {
                $evidenceFailures[] = 'Steady-state phases must last at least 180 seconds: ' . $phaseName;
            }
        }

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

        $deltas[$phaseName] = [
            'throughput_ratio' => $throughputRatio,
            'combined_rps_cv_percent' => sqrt($a['rps_cv_percent'] ** 2 + $f['rps_cv_percent'] ** 2),
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
            $failures[] = $phaseName . ': AUTO throughput regressed more than 5%.';
        }
        if ($p95Ratio > 1.15 && $p95AbsoluteDelta > 0.5) {
            $failures[] = $phaseName . ': AUTO p95 regressed more than 15% and 0.5 ms.';
        }
        if ($p99Ratio > 1.15 && $p99AbsoluteDelta > 0.5) {
            $failures[] = $phaseName . ': AUTO p99 regressed more than 15% and 0.5 ms.';
        }
        if ((float) $a['cpu_percent'] > $cpuLimit) {
            $failures[] = $phaseName . ': AUTO CPU exceeded the regression budget.';
        }
        if ((float) $a['rss_peak_bytes'] > $rssLimit) {
            $failures[] = $phaseName . ': AUTO RSS exceeded the regression budget.';
        }
        if ((float) $a['fairness_ratio'] > $fairnessLimit) {
            $failures[] = $phaseName . ': AUTO fairness exceeded the regression budget.';
        }
    }

    $result = [
        'protocol' => $auto['protocol'],
        'baseline_mode' => $baselineMode,
        'workload' => $auto['workload'],
        'scope' => 'single-workload',
        'diagnostic' => $diagnostic,
        'promotion_certified' => false,
        'evidence_failures' => array_values(array_unique($evidenceFailures)),
        'measurement_qualified' => $evidenceFailures === [],
        'trials' => min((int) $auto['trials'], (int) $fixed['trials']),
        'budgets' => [
            'throughput_floor_ratio' => 0.95,
            'rps_cv_ceiling_percent' => 2.5,
            'minimum_steady_phase_seconds' => 180,
            'minimum_warmup_seconds' => 30,
            'latency_ceiling_ratio' => 1.15,
            'latency_absolute_tolerance_ms' => 0.5,
            'latency_rule' => 'fail only when relative and absolute latency budgets are both exceeded',
            'cpu_ceiling' => 'fixed * 1.15 + 5 percentage points',
            'rss_ceiling' => 'fixed * 1.10 + 4 MiB',
            'fairness_ceiling' => 'max(1.25, fixed * 1.25)',
        ],
        'phases' => $deltas,
        'failures' => $failures,
        'passed' => $failures === [] && ($diagnostic || $evidenceFailures === []),
    ];

    return $result;
}

if (realpath($argv[0] ?? '') === __FILE__) {
    if ($argc < 3) {
        throw new InvalidArgumentException('Usage: php adaptive_matrix_compare.php <auto-summary> <baseline-summary> [--diagnostic] [--baseline=fixed|latency|throughput]');
    }
    $diagnostic = false;
    $baselineMode = 'fixed';
    foreach (array_slice($argv, 3) as $option) {
        if ($option === '--diagnostic') {
            $diagnostic = true;
        } elseif (str_starts_with($option, '--baseline=')) {
            $baselineMode = substr($option, strlen('--baseline='));
        } else {
            throw new InvalidArgumentException('Unknown adaptive comparison option: ' . $option);
        }
    }
    $result = adaptiveMatrixCompare(adaptiveCompareLoad($argv[1]), adaptiveCompareLoad($argv[2]), $diagnostic, $baselineMode);
    fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
    if (!$result['passed']) {
        throw new RuntimeException('Adaptive comparison failed regression or sustained-evidence requirements.');
    }
}
