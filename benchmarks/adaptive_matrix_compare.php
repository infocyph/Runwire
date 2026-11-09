<?php

declare(strict_types=1);

/**
 * @return array<string, mixed>
 * @throws \RuntimeException
 */
function adaptiveCompareLoad(string $path): array
{
    if (!is_file($path) || !is_readable($path)) {
        throw new RuntimeException('Adaptive comparison requires a readable local file.');
    }
    $contents = file_get_contents($path);
    if (!is_string($contents)) {
        throw new RuntimeException('Unable to read adaptive comparison input: ' . $path);
    }
    $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($decoded)) {
        throw new RuntimeException('Adaptive comparison input must decode to an object.');
    }

    $record = [];
    foreach ($decoded as $name => $value) {
        if (!is_string($name)) {
            throw new RuntimeException('Adaptive comparison input requires string field names.');
        }
        $record[$name] = $value;
    }

    return $record;
}

require_once __DIR__ . '/adaptive_matrix_summary.php';

/**
 * @param array<string,mixed> $auto
 * @param array<string,mixed> $fixed
 * @throws RuntimeException
 */
function adaptiveCompareIdentity(array $auto, array $fixed, string $baselineMode): string
{
    if (!in_array($baselineMode, ['fixed', 'latency', 'throughput'], true)) {
        throw new RuntimeException('Invalid adaptive comparison baseline mode.');
    }
    if (!is_string($auto['protocol'] ?? null) || $auto['protocol'] !== ($fixed['protocol'] ?? null)) {
        throw new RuntimeException('Adaptive comparison protocol mismatch.');
    }
    if (($auto['mode'] ?? null) !== 'auto' || ($fixed['mode'] ?? null) !== $baselineMode) {
        throw new RuntimeException('Adaptive comparison requires AUTO then the selected baseline summaries.');
    }
    if (($auto['correctness_passed'] ?? false) !== true || ($fixed['correctness_passed'] ?? false) !== true) {
        throw new RuntimeException('Adaptive comparison requires correct summaries.');
    }

    return $auto['protocol'];
}

/**
 * @param array<string,mixed> $auto
 * @param array<string,mixed> $fixed
 * @throws RuntimeException
 */
function adaptiveCompareMetadata(array $auto, array $fixed): void
{
    foreach (['workload', 'payload_bytes', 'runtime_build', 'environment', 'warmup_seconds'] as $name) {
        if (!isset($auto[$name], $fixed[$name]) || $auto[$name] !== $fixed[$name]) {
            throw new RuntimeException('Adaptive comparison metadata mismatch: ' . $name);
        }
    }
}

/**
 * @param array<string,mixed> $auto
 * @param array<string,mixed> $fixed
 * @return array{auto:float,fixed:float}
 * @throws RuntimeException
 */
function adaptiveCompareTrialIdentity(array $auto, array $fixed): array
{
    $autoTrials = adaptiveMatrixMetric($auto, 'trials');
    $fixedTrials = adaptiveMatrixMetric($fixed, 'trials');
    if ($autoTrials < 5 || $fixedTrials < 5) {
        throw new RuntimeException('Adaptive comparison requires at least five trials per mode.');
    }
    if (($auto['case'] ?? null) !== ($fixed['case'] ?? null)) {
        throw new RuntimeException('Adaptive comparison case mismatch.');
    }

    return ['auto' => $autoTrials, 'fixed' => $fixedTrials];
}

/**
 * @param array<string,mixed> $a
 * @param array<string,mixed> $f
 * @throws RuntimeException
 */
function adaptiveComparePhaseConfiguration(array $a, array $f): void
{
    foreach (['concurrency', 'requested_duration_seconds'] as $name) {
        if (adaptiveMatrixMetric($a, $name) <= 0 || $a[$name] !== ($f[$name] ?? null)) {
            throw new RuntimeException('Adaptive comparison phase mismatch: ' . $name);
        }
    }
}

/**
 * @param array<string,mixed> $phase
 * @return list<string>
 * @throws RuntimeException
 */
function adaptiveComparePhaseEvidence(array $phase, string $phaseName, bool $promotion): array
{
    $evidenceFailures = [];
    if ($promotion && adaptiveMatrixMetric($phase, 'client_cpu_percent_max') >= 85) {
        $evidenceFailures[] = 'Load generator CPU may limit throughput: ' . $phaseName;
    }
    foreach (['throughput_rps', 'p95_ms', 'p99_ms', 'cpu_percent', 'rss_peak_bytes', 'fairness_ratio', 'rps_cv_percent', 'duration_seconds_min'] as $metric) {
        adaptiveMatrixMetric($phase, $metric);
    }
    if (adaptiveMatrixMetric($phase, 'throughput_rps') <= 0 || adaptiveMatrixMetric($phase, 'fairness_ratio') < 1) {
        throw new RuntimeException('Adaptive comparison has invalid throughput or fairness.');
    }
    if (adaptiveMatrixMetric($phase, 'rps_cv_percent') >= 2.5) {
        $evidenceFailures[] = 'Throughput CV must be below 2.5%: ' . $phaseName;
    }
    if (!str_starts_with($phaseName, 'transition_')
        && (adaptiveMatrixMetric($phase, 'duration_seconds_min') < 180
            || adaptiveMatrixMetric($phase, 'requested_duration_seconds') < 180)) {
        $evidenceFailures[] = 'Steady-state phases must last at least 180 seconds: ' . $phaseName;
    }

    return $evidenceFailures;
}

/**
 * @param array<string,mixed> $a
 * @param array<string,mixed> $f
 * @return array<string,float>
 * @throws RuntimeException
 */
function adaptiveCompareRawDelta(array $a, array $f): array
{
    $throughputRatio = adaptiveMatrixMetric($a, 'throughput_rps') / max(0.001, adaptiveMatrixMetric($f, 'throughput_rps'));
    $autoP95 = adaptiveMatrixMetric($a, 'p95_ms');
    $fixedP95 = adaptiveMatrixMetric($f, 'p95_ms');
    $autoP99 = adaptiveMatrixMetric($a, 'p99_ms');
    $fixedP99 = adaptiveMatrixMetric($f, 'p99_ms');
    $p95Ratio = $autoP95 / max(0.001, $fixedP95);
    $p99Ratio = $autoP99 / max(0.001, $fixedP99);
    $p95AbsoluteDelta = $autoP95 - $fixedP95;
    $p99AbsoluteDelta = $autoP99 - $fixedP99;

    return [
        'throughput_ratio' => $throughputRatio,
        'combined_rps_cv_percent' => sqrt(adaptiveMatrixMetric($a, 'rps_cv_percent') ** 2.0 + adaptiveMatrixMetric($f, 'rps_cv_percent') ** 2.0),
        'p95_ratio' => $p95Ratio,
        'p99_ratio' => $p99Ratio,
        'p95_absolute_delta_ms' => $p95AbsoluteDelta,
        'p99_absolute_delta_ms' => $p99AbsoluteDelta,
        'auto_cpu_percent' => adaptiveMatrixMetric($a, 'cpu_percent'),
        'fixed_cpu_percent' => adaptiveMatrixMetric($f, 'cpu_percent'),
        'auto_rss_peak_bytes' => adaptiveMatrixMetric($a, 'rss_peak_bytes'),
        'fixed_rss_peak_bytes' => adaptiveMatrixMetric($f, 'rss_peak_bytes'),
        'auto_fairness_ratio' => adaptiveMatrixMetric($a, 'fairness_ratio'),
        'fixed_fairness_ratio' => adaptiveMatrixMetric($f, 'fairness_ratio'),
    ];
}

/**
 * @param array<string,float> $delta
 * @return list<string>
 */
function adaptiveCompareRegressions(array $delta, string $phaseName): array
{
    $failures = [];
    $cpuLimit = ($delta['fixed_cpu_percent'] * 1.15) + 5.0;
    $rssLimit = ($delta['fixed_rss_peak_bytes'] * 1.10) + 4_194_304.0;
    $fairnessLimit = max(1.25, $delta['fixed_fairness_ratio'] * 1.25);

    if ($delta['throughput_ratio'] < 0.95) {
        $failures[] = $phaseName . ': AUTO throughput regressed more than 5%.';
    }
    if ($delta['p95_ratio'] > 1.15 && $delta['p95_absolute_delta_ms'] > 0.5) {
        $failures[] = $phaseName . ': AUTO p95 regressed more than 15% and 0.5 ms.';
    }
    if ($delta['p99_ratio'] > 1.15 && $delta['p99_absolute_delta_ms'] > 0.5) {
        $failures[] = $phaseName . ': AUTO p99 regressed more than 15% and 0.5 ms.';
    }
    if ($delta['auto_cpu_percent'] > $cpuLimit) {
        $failures[] = $phaseName . ': AUTO CPU exceeded the regression budget.';
    }
    if ($delta['auto_rss_peak_bytes'] > $rssLimit) {
        $failures[] = $phaseName . ': AUTO RSS exceeded the regression budget.';
    }
    if ($delta['auto_fairness_ratio'] > $fairnessLimit) {
        $failures[] = $phaseName . ': AUTO fairness exceeded the regression budget.';
    }

    return $failures;
}

/**
 * @param array<string,float> $raw
 * @return array<string,int|float>
 */
function adaptiveCompareReportDelta(array $raw): array
{
    $report = $raw;
    foreach (['p95_ratio', 'p99_ratio', 'p95_absolute_delta_ms', 'p99_absolute_delta_ms'] as $name) {
        $report[$name] = round($raw[$name], 4);
    }
    $report['auto_rss_peak_bytes'] = (int) $raw['auto_rss_peak_bytes'];
    $report['fixed_rss_peak_bytes'] = (int) $raw['fixed_rss_peak_bytes'];

    return $report;
}

/**
 * @param array<string, mixed> $auto
 * @param array<string, mixed> $fixed
 * @return array{protocol:mixed,baseline_mode:string,workload:mixed,scope:string,diagnostic:bool,promotion_certified:bool,evidence_failures:list<string>,measurement_qualified:bool,trials:int,budgets:array<string,int|float|string>,phases:array<string,array<string,int|float>>,failures:list<string>,passed:bool}
 * @throws \RuntimeException
 */
function adaptiveMatrixCompare(array $auto, array $fixed, bool $diagnostic = false, string $baselineMode = 'fixed'): array
{
    $protocol = adaptiveCompareIdentity($auto, $fixed, $baselineMode);
    adaptiveCompareMetadata($auto, $fixed);
    $trials = adaptiveCompareTrialIdentity($auto, $fixed);
    $autoTrials = $trials['auto'];
    $fixedTrials = $trials['fixed'];
    $evidenceFailures = [];
    if (adaptiveMatrixMetric($auto, 'warmup_seconds') < 30) {
        $evidenceFailures[] = 'Sustained evidence requires at least 30 seconds of warm-up.';
    }
    $phaseNames = ['low_before', 'transition_up', 'high', 'transition_down', 'low_after'];
    if (isset($auto['case'])) {
        $phaseNames[] = 'medium';
    }
    $autoPhases = $auto['phases'] ?? null;
    $fixedPhases = $fixed['phases'] ?? null;
    if (!is_array($autoPhases) || !is_array($fixedPhases)) {
        throw new RuntimeException('Adaptive comparison requires named phase summaries.');
    }

    $failures = [];
    $deltas = [];
    foreach ($phaseNames as $phaseName) {
        $a = adaptiveMatrixPhase($autoPhases[$phaseName] ?? null, $phaseName);
        $f = adaptiveMatrixPhase($fixedPhases[$phaseName] ?? null, $phaseName);
        adaptiveComparePhaseConfiguration($a, $f);
        foreach ([$a, $f] as $phase) {
            array_push($evidenceFailures, ...adaptiveComparePhaseEvidence($phase, $phaseName, isset($auto['case'])));
        }
        $raw = adaptiveCompareRawDelta($a, $f);
        array_push($failures, ...adaptiveCompareRegressions($raw, $phaseName));
        // Evaluate all budgets before rounding values for the evidence report.
        $deltas[$phaseName] = adaptiveCompareReportDelta($raw);
    }

    $result = [
        'protocol' => $protocol,
        'baseline_mode' => $baselineMode,
        'workload' => $auto['workload'],
        'scope' => 'single-workload',
        'diagnostic' => $diagnostic,
        'promotion_certified' => false,
        'evidence_failures' => array_values(array_unique($evidenceFailures)),
        'measurement_qualified' => $evidenceFailures === [],
        'trials' => (int) min($autoTrials, $fixedTrials),
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
    if (!isset($argc, $argv)) {
        throw new \RuntimeException('CLI arguments are unavailable.');
    }
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
