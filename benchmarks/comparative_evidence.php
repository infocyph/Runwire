<?php

declare(strict_types=1);

const REQUIRED_STRING_FIELDS = [
    'runtime',
    'runtime_version',
    'protocol',
    'workload',
    'hardware_id',
    'php_version',
    'instrumentation',
    'host_os',
    'host_cpu',
    'tls',
    'opcache',
    'runtime_build',
    'connection_reuse',
];

const REQUIRED_NUMERIC_FIELDS = [
    'workers',
    'concurrency',
    'duration_seconds',
    'requests_total',
    'completed_requests',
    'successful_requests',
    'throughput_rps',
    'errors_total',
    'timeouts_total',
    'validation_failures',
    'error_rate',
    'cpu_percent',
    'rss_peak_bytes',
];

const COMPARABILITY_FIELDS = [
    'hardware_id',
    'php_version',
    'protocol',
    'workload',
    'instrumentation',
    'workers',
    'concurrency',
    'duration_seconds',
    'tls',
    'opcache',
    'connection_reuse',
];

/** @return array<string, mixed> */
function loadEvidence(string $path): array
{
    if (!is_file($path) || !is_readable($path)) {
        throw new RuntimeException(sprintf('Evidence file "%s" is not readable.', $path));
    }

    $decoded = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    if (!is_array($decoded)) {
        throw new RuntimeException(sprintf('Evidence file "%s" must contain a JSON object.', $path));
    }

    foreach (REQUIRED_STRING_FIELDS as $field) {
        if (!isset($decoded[$field]) || !is_string($decoded[$field]) || trim($decoded[$field]) === '') {
            throw new RuntimeException(sprintf('Evidence file "%s" is missing string field "%s".', $path, $field));
        }
    }
    foreach (REQUIRED_NUMERIC_FIELDS as $field) {
        if (!isset($decoded[$field]) || (!is_int($decoded[$field]) && !is_float($decoded[$field]))) {
            throw new RuntimeException(sprintf('Evidence file "%s" is missing numeric field "%s".', $path, $field));
        }
        if (!is_finite((float) $decoded[$field])) {
            throw new RuntimeException(sprintf('Evidence file "%s" has non-finite field "%s".', $path, $field));
        }
    }
    if (!array_key_exists('correctness_passed', $decoded) || !is_bool($decoded['correctness_passed'])) {
        throw new RuntimeException(sprintf('Evidence file "%s" is missing boolean field "correctness_passed".', $path));
    }

    $extensions = $decoded['extension_versions'] ?? null;
    if (!is_array($extensions)) {
        throw new RuntimeException(sprintf('Evidence file "%s" is missing extension_versions.', $path));
    }
    foreach ($extensions as $extension => $version) {
        if (!is_string($extension) || $extension === '' || !is_string($version) || $version === '') {
            throw new RuntimeException(sprintf('Evidence file "%s" has invalid extension_versions.', $path));
        }
    }

    $latency = $decoded['latency_ms'] ?? null;
    if (!is_array($latency)) {
        throw new RuntimeException(sprintf('Evidence file "%s" is missing latency_ms.', $path));
    }
    foreach (['p50', 'p95', 'p99'] as $field) {
        if (!isset($latency[$field]) || (!is_int($latency[$field]) && !is_float($latency[$field]))) {
            throw new RuntimeException(sprintf('Evidence file "%s" is missing latency_ms.%s.', $path, $field));
        }
        if (!is_finite((float) $latency[$field]) || (float) $latency[$field] < 0.0) {
            throw new RuntimeException(sprintf('Evidence file "%s" has invalid latency_ms.%s.', $path, $field));
        }
    }

    assertEvidenceRanges($decoded, $path);

    return $decoded;
}

/** @param array<string, mixed> $record */
function assertEvidenceRanges(array $record, string $path): void
{
    foreach (['workers', 'concurrency', 'duration_seconds'] as $field) {
        if ((float) $record[$field] <= 0.0) {
            throw new RuntimeException(sprintf('Evidence file "%s" requires "%s" to be greater than zero.', $path, $field));
        }
    }
    foreach ([
        'requests_total',
        'completed_requests',
        'successful_requests',
        'throughput_rps',
        'errors_total',
        'timeouts_total',
        'validation_failures',
        'cpu_percent',
        'rss_peak_bytes',
    ] as $field) {
        if ((float) $record[$field] < 0.0) {
            throw new RuntimeException(sprintf('Evidence file "%s" requires "%s" to be non-negative.', $path, $field));
        }
    }

    $requests = (int) $record['requests_total'];
    $completed = (int) $record['completed_requests'];
    $successful = (int) $record['successful_requests'];
    $errors = (int) $record['errors_total'];
    $timeouts = (int) $record['timeouts_total'];
    $validationFailures = (int) $record['validation_failures'];
    if ($successful > $completed || $completed > $requests) {
        throw new RuntimeException(sprintf('Evidence file "%s" has impossible request completion counts.', $path));
    }
    if ($validationFailures > $completed || $completed + $errors + $timeouts !== $requests) {
        throw new RuntimeException(sprintf('Evidence file "%s" has inconsistent request outcome counts.', $path));
    }

    $errorRate = (float) $record['error_rate'];
    if ($errorRate < 0.0 || $errorRate > 1.0) {
        throw new RuntimeException(sprintf('Evidence file "%s" requires "error_rate" between 0 and 1.', $path));
    }

    /** @var array{p50: int|float, p95: int|float, p99: int|float} $latency */
    $latency = $record['latency_ms'];
    if ((float) $latency['p50'] > (float) $latency['p95'] || (float) $latency['p95'] > (float) $latency['p99']) {
        throw new RuntimeException(sprintf('Evidence file "%s" requires ordered p50 <= p95 <= p99 latency.', $path));
    }
}

/** @param list<array<string, mixed>> $records */
function assertComparable(array $records): void
{
    if (count($records) < 2) {
        throw new RuntimeException('At least two evidence files are required for a comparison.');
    }

    $reference = $records[0];
    foreach (COMPARABILITY_FIELDS as $field) {
        $expected = $reference[$field] ?? null;
        foreach ($records as $record) {
            if (($record[$field] ?? null) !== $expected) {
                throw new RuntimeException(sprintf('Comparative evidence mismatch for "%s".', $field));
            }
        }
    }
}

/** @param list<array<string, mixed>> $records */
function renderMarkdown(array $records): string
{
    usort(
        $records,
        static fn(array $left, array $right): int => strcmp((string) $left['runtime'], (string) $right['runtime']),
    );

    $lines = [
        '| Runtime | Version | RPS | Successful | Timeouts | Validation failures | p50 ms | p95 ms | p99 ms | Error rate | CPU % | Peak RSS bytes |',
        '| --- | --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: |',
    ];

    foreach ($records as $record) {
        /** @var array<string, int|float> $latency */
        $latency = $record['latency_ms'];
        $lines[] = sprintf(
            '| %s | %s | %.2f | %d | %d | %d | %.3f | %.3f | %.3f | %.6f | %.2f | %d |',
            $record['runtime'],
            $record['runtime_version'],
            (float) $record['throughput_rps'],
            (int) $record['successful_requests'],
            (int) $record['timeouts_total'],
            (int) $record['validation_failures'],
            (float) $latency['p50'],
            (float) $latency['p95'],
            (float) $latency['p99'],
            (float) $record['error_rate'],
            (float) $record['cpu_percent'],
            (int) $record['rss_peak_bytes'],
        );
    }

    return implode(PHP_EOL, $lines) . PHP_EOL;
}

function runComparativeEvidence(array $arguments): void
{
    $records = array_map(loadEvidence(...), array_slice($arguments, 1));
    assertComparable($records);
    fwrite(STDOUT, renderMarkdown($records));
}

if (realpath($argv[0] ?? '') === __FILE__) {
    runComparativeEvidence($argv);
}
