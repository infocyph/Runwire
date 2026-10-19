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
        'protocol', 'workload', 'php_version', 'hardware_id', 'host_os', 'host_cpu',
        'instrumentation', 'tls', 'opcache', 'connection_reuse',
        'runtime_build',
    ] as $field) {
        if (!isset($decoded[$field]) || !is_string($decoded[$field]) || $decoded[$field] === '') {
            throw new RuntimeException(sprintf('Summary "%s" requires string "%s".', $path, $field));
        }
    }
    foreach ([
        'trials', 'duration_seconds_per_trial', 'workers', 'concurrency',
        'median_successful_rps', 'median_successful_rpm', 'rps_cv_percent',
        'median_p99_ms', 'peak_rss_bytes',
    ] as $field) {
        if (!isset($decoded[$field]) || (!is_int($decoded[$field]) && !is_float($decoded[$field]))
            || !is_finite((float) $decoded[$field])) {
            throw new RuntimeException(sprintf('Summary "%s" requires finite numeric "%s".', $path, $field));
        }
    }
    if (($decoded['trials'] < 5) || $decoded['duration_seconds_per_trial'] <= 0
        || $decoded['workers'] <= 0 || $decoded['concurrency'] <= 0
        || $decoded['median_successful_rps'] <= 0 || $decoded['median_successful_rpm'] <= 0
        || $decoded['rps_cv_percent'] < 0 || $decoded['median_p99_ms'] < 0
        || $decoded['peak_rss_bytes'] < 0) {
        throw new RuntimeException(sprintf('Summary "%s" has invalid measurement ranges.', $path));
    }
    if (($decoded['correctness_passed'] ?? null) !== true) {
        throw new RuntimeException(sprintf('Summary "%s" did not pass correctness.', $path));
    }
    if (!isset($decoded['extension_versions']) || !is_array($decoded['extension_versions'])) {
        throw new RuntimeException(sprintf('Summary "%s" is missing extension version provenance.', $path));
    }
    foreach ($decoded['extension_versions'] as $name => $version) {
        if (!is_string($name) || $name === '' || !is_string($version) || $version === '') {
            throw new RuntimeException(sprintf('Summary "%s" has invalid extension version provenance.', $path));
        }
    }

    return $decoded;
}

$baseline = loadSummary($argv[1]);
$candidate = loadSummary($argv[2]);

foreach ([
    'protocol', 'workload', 'php_version', 'hardware_id', 'host_os', 'host_cpu',
    'instrumentation', 'tls', 'opcache', 'connection_reuse', 'workers', 'concurrency',
    'duration_seconds_per_trial', 'trials', 'extension_versions',
] as $field) {
    if ($baseline[$field] !== $candidate[$field]) {
        throw new RuntimeException(sprintf('Benchmark summary mismatch for "%s".', $field));
    }
}

$baselineRps = (float) $baseline['median_successful_rps'];
$candidateRps = (float) $candidate['median_successful_rps'];
$baselineCv = (float) $baseline['rps_cv_percent'];
$candidateCv = (float) $candidate['rps_cv_percent'];
$maxCv = max($baselineCv, $candidateCv);
$regressionPercent = (($candidateRps - $baselineRps) / $baselineRps) * 100.0;
$budgetPercent = 5.0;
$varianceCeilingPercent = 2.5;
$enforced = $maxCv < $varianceCeilingPercent;
$passed = !$enforced || $regressionPercent >= -$budgetPercent;

$baselineP99 = (float) $baseline['median_p99_ms'];
$candidateP99 = (float) $candidate['median_p99_ms'];
$baselineRss = (float) $baseline['peak_rss_bytes'];
$candidateRss = (float) $candidate['peak_rss_bytes'];

$result = [
    'baseline_build' => $baseline['runtime_build'],
    'candidate_build' => $candidate['runtime_build'],
    'baseline_median_successful_rps' => round($baselineRps, 3),
    'candidate_median_successful_rps' => round($candidateRps, 3),
    'delta_percent' => round($regressionPercent, 3),
    'baseline_rps_cv_percent' => round($baselineCv, 3),
    'candidate_rps_cv_percent' => round($candidateCv, 3),
    'max_rps_cv_percent' => round($maxCv, 3),
    'baseline_median_p99_ms' => $baselineP99,
    'candidate_median_p99_ms' => $candidateP99,
    'p99_delta_percent' => $baselineP99 > 0 ? round(($candidateP99 - $baselineP99) / $baselineP99 * 100.0, 3) : null,
    'baseline_peak_rss_bytes' => (int) $baselineRss,
    'candidate_peak_rss_bytes' => (int) $candidateRss,
    'rss_delta_percent' => $baselineRss > 0 ? round(($candidateRss - $baselineRss) / $baselineRss * 100.0, 3) : null,
    'regression_budget_percent' => $budgetPercent,
    'variance_ceiling_percent' => $varianceCeilingPercent,
    'budget_enforced' => $enforced,
    'passed' => $passed,
];

fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);

if (!$passed) {
    throw new RuntimeException('Stable benchmark evidence exceeds the permitted regression budget.');
}
