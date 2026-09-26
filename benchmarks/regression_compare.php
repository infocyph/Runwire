<?php

declare(strict_types=1);

if ($argc !== 3) {
    throw new InvalidArgumentException('Usage: php regression_compare.php <baseline-summary.json> <candidate-summary.json>');
}

/** @return array<string, mixed> */
function loadSummary(string $path): array
{
    if (!is_file($path) || !is_readable($path)) {
        throw new RuntimeException(sprintf('Summary "%s" is not readable.', $path));
    }

    $decoded = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    if (!is_array($decoded)) {
        throw new RuntimeException(sprintf('Summary "%s" must contain a JSON object.', $path));
    }

    foreach ([
        'protocol',
        'workload',
        'php_version',
        'hardware_id',
        'instrumentation',
        'median_successful_rps',
        'median_successful_rpm',
        'rps_cv_percent',
        'correctness_passed',
    ] as $field) {
        if (!array_key_exists($field, $decoded)) {
            throw new RuntimeException(sprintf('Summary "%s" is missing "%s".', $path, $field));
        }
    }

    return $decoded;
}

$baseline = loadSummary($argv[1]);
$candidate = loadSummary($argv[2]);

foreach (['protocol', 'workload', 'php_version', 'hardware_id', 'instrumentation'] as $field) {
    if ($baseline[$field] !== $candidate[$field]) {
        throw new RuntimeException(sprintf('Benchmark summary mismatch for "%s".', $field));
    }
}
if ($baseline['correctness_passed'] !== true || $candidate['correctness_passed'] !== true) {
    throw new RuntimeException('Regression comparison requires correctness-passing summaries.');
}

$baselineRps = (float) $baseline['median_successful_rps'];
$candidateRps = (float) $candidate['median_successful_rps'];
if ($baselineRps <= 0.0 || $candidateRps <= 0.0) {
    throw new RuntimeException('Regression comparison requires positive median successful RPS.');
}

$baselineCv = (float) $baseline['rps_cv_percent'];
$candidateCv = (float) $candidate['rps_cv_percent'];
$maxCv = max($baselineCv, $candidateCv);
$regressionPercent = (($candidateRps - $baselineRps) / $baselineRps) * 100.0;
$budgetPercent = 5.0;
$varianceCeilingPercent = 2.5;
$enforced = $maxCv < $varianceCeilingPercent;
$passed = !$enforced || $regressionPercent >= -$budgetPercent;

$result = [
    'baseline_build' => $baseline['runtime_build'] ?? 'unknown',
    'candidate_build' => $candidate['runtime_build'] ?? 'unknown',
    'baseline_median_successful_rps' => round($baselineRps, 3),
    'candidate_median_successful_rps' => round($candidateRps, 3),
    'delta_percent' => round($regressionPercent, 3),
    'baseline_rps_cv_percent' => round($baselineCv, 3),
    'candidate_rps_cv_percent' => round($candidateCv, 3),
    'max_rps_cv_percent' => round($maxCv, 3),
    'regression_budget_percent' => $budgetPercent,
    'variance_ceiling_percent' => $varianceCeilingPercent,
    'budget_enforced' => $enforced,
    'passed' => $passed,
];

fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);

if (!$passed) {
    throw new RuntimeException('Stable benchmark evidence exceeds the permitted regression budget.');
}
