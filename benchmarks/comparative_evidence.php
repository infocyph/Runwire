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

/**
 * @param array<array-key,mixed> $record
 * @throws RuntimeException
 */
function evidenceString(array $record, string $field, string $path): string
{
    $value = $record[$field] ?? null;
    if (!is_string($value) || trim($value) === '') {
        throw new RuntimeException(sprintf('Evidence file "%s" is missing string field "%s".', $path, $field));
    }

    return $value;
}

/**
 * @param array<array-key,mixed> $record
 * @throws RuntimeException
 */
function evidenceNumber(array $record, string $field, string $path): int|float
{
    $value = $record[$field] ?? null;
    if (!is_int($value) && !is_float($value)) {
        throw new RuntimeException(sprintf('Evidence file "%s" is missing numeric field "%s".', $path, $field));
    }
    if (!is_finite((float) $value)) {
        throw new RuntimeException(sprintf('Evidence file "%s" has non-finite field "%s".', $path, $field));
    }

    return $value;
}

/**
 * @param array<array-key,mixed> $latency
 * @throws RuntimeException
 */
function evidenceLatency(array $latency, string $field, string $path): int|float
{
    $value = evidenceNumber($latency, $field, $path);
    if ($value < 0) {
        throw new RuntimeException(sprintf('Evidence file "%s" has invalid latency_ms.%s.', $path, $field));
    }

    return $value;
}

/**
 * @return array<string,string>
 * @throws RuntimeException
 */
function evidenceExtensions(mixed $value, string $path): array
{
    if (!is_array($value)) {
        throw new RuntimeException(sprintf('Evidence file "%s" is missing extension_versions.', $path));
    }
    $extensions = [];
    foreach ($value as $name => $version) {
        if (!is_string($name) || $name === '' || !is_string($version) || $version === '') {
            throw new RuntimeException(sprintf('Evidence file "%s" has invalid extension_versions.', $path));
        }
        $extensions[$name] = $version;
    }

    return $extensions;
}

/**
 * @return array{runtime:string,runtime_version:string,protocol:string,workload:string,hardware_id:string,php_version:string,instrumentation:string,host_os:string,host_cpu:string,tls:string,opcache:string,runtime_build:string,connection_reuse:string,workers:int|float,concurrency:int|float,duration_seconds:int|float,requests_total:int|float,completed_requests:int|float,successful_requests:int|float,throughput_rps:int|float,errors_total:int|float,timeouts_total:int|float,validation_failures:int|float,error_rate:int|float,cpu_percent:int|float,rss_peak_bytes:int|float,correctness_passed:bool,extension_versions:array<string,string>,latency_ms:array{p50:int|float,p95:int|float,p99:int|float}}
 * @throws \RuntimeException
 */
function loadEvidence(string $path): array
{
    if (!is_file($path) || !is_readable($path)) {
        throw new RuntimeException(sprintf('Evidence file "%s" is not readable.', $path));
    }

    $decoded = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    if (!is_array($decoded)) {
        throw new RuntimeException(sprintf('Evidence file "%s" must contain a JSON object.', $path));
    }

    if (!is_bool($decoded['correctness_passed'] ?? null)) {
        throw new RuntimeException(sprintf('Evidence file "%s" is missing boolean field "correctness_passed".', $path));
    }
    $latency = $decoded['latency_ms'] ?? null;
    if (!is_array($latency)) {
        throw new RuntimeException(sprintf('Evidence file "%s" is missing latency_ms.', $path));
    }
    $record = [
        'runtime' => evidenceString($decoded, 'runtime', $path),
        'runtime_version' => evidenceString($decoded, 'runtime_version', $path),
        'protocol' => evidenceString($decoded, 'protocol', $path),
        'workload' => evidenceString($decoded, 'workload', $path),
        'hardware_id' => evidenceString($decoded, 'hardware_id', $path),
        'php_version' => evidenceString($decoded, 'php_version', $path),
        'instrumentation' => evidenceString($decoded, 'instrumentation', $path),
        'host_os' => evidenceString($decoded, 'host_os', $path),
        'host_cpu' => evidenceString($decoded, 'host_cpu', $path),
        'tls' => evidenceString($decoded, 'tls', $path),
        'opcache' => evidenceString($decoded, 'opcache', $path),
        'runtime_build' => evidenceString($decoded, 'runtime_build', $path),
        'connection_reuse' => evidenceString($decoded, 'connection_reuse', $path),
        'workers' => evidenceNumber($decoded, 'workers', $path),
        'concurrency' => evidenceNumber($decoded, 'concurrency', $path),
        'duration_seconds' => evidenceNumber($decoded, 'duration_seconds', $path),
        'requests_total' => evidenceNumber($decoded, 'requests_total', $path),
        'completed_requests' => evidenceNumber($decoded, 'completed_requests', $path),
        'successful_requests' => evidenceNumber($decoded, 'successful_requests', $path),
        'throughput_rps' => evidenceNumber($decoded, 'throughput_rps', $path),
        'errors_total' => evidenceNumber($decoded, 'errors_total', $path),
        'timeouts_total' => evidenceNumber($decoded, 'timeouts_total', $path),
        'validation_failures' => evidenceNumber($decoded, 'validation_failures', $path),
        'error_rate' => evidenceNumber($decoded, 'error_rate', $path),
        'cpu_percent' => evidenceNumber($decoded, 'cpu_percent', $path),
        'rss_peak_bytes' => evidenceNumber($decoded, 'rss_peak_bytes', $path),
        'correctness_passed' => $decoded['correctness_passed'],
        'extension_versions' => evidenceExtensions($decoded['extension_versions'] ?? null, $path),
        'latency_ms' => [
            'p50' => evidenceLatency($latency, 'p50', $path),
            'p95' => evidenceLatency($latency, 'p95', $path),
            'p99' => evidenceLatency($latency, 'p99', $path),
        ],
    ] + $decoded;

    assertEvidenceRanges($record, $path);

    return $record;
}

/**
 * @param array{runtime:string,runtime_version:string,protocol:string,workload:string,hardware_id:string,php_version:string,instrumentation:string,host_os:string,host_cpu:string,tls:string,opcache:string,runtime_build:string,connection_reuse:string,workers:int|float,concurrency:int|float,duration_seconds:int|float,requests_total:int|float,completed_requests:int|float,successful_requests:int|float,throughput_rps:int|float,errors_total:int|float,timeouts_total:int|float,validation_failures:int|float,error_rate:int|float,cpu_percent:int|float,rss_peak_bytes:int|float,correctness_passed:bool,extension_versions:array<string,string>,latency_ms:array{p50:int|float,p95:int|float,p99:int|float}} $record
 * @throws \RuntimeException
 */
function assertEvidenceCounts(array $record, string $path): void
{
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

}

/**
 * @param array{runtime:string,runtime_version:string,protocol:string,workload:string,hardware_id:string,php_version:string,instrumentation:string,host_os:string,host_cpu:string,tls:string,opcache:string,runtime_build:string,connection_reuse:string,workers:int|float,concurrency:int|float,duration_seconds:int|float,requests_total:int|float,completed_requests:int|float,successful_requests:int|float,throughput_rps:int|float,errors_total:int|float,timeouts_total:int|float,validation_failures:int|float,error_rate:int|float,cpu_percent:int|float,rss_peak_bytes:int|float,correctness_passed:bool,extension_versions:array<string,string>,latency_ms:array{p50:int|float,p95:int|float,p99:int|float}} $record
 * @throws \RuntimeException
 */
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

    assertEvidenceCounts($record, $path);

    $errorRate = (float) $record['error_rate'];
    if ($errorRate < 0.0 || $errorRate > 1.0) {
        throw new RuntimeException(sprintf('Evidence file "%s" requires "error_rate" between 0 and 1.', $path));
    }

    $latency = $record['latency_ms'];
    if ((float) $latency['p50'] > (float) $latency['p95'] || (float) $latency['p95'] > (float) $latency['p99']) {
        throw new RuntimeException(sprintf('Evidence file "%s" requires ordered p50 <= p95 <= p99 latency.', $path));
    }
}

/**
 * @param list<array{runtime:string,runtime_version:string,protocol:string,workload:string,hardware_id:string,php_version:string,instrumentation:string,host_os:string,host_cpu:string,tls:string,opcache:string,runtime_build:string,connection_reuse:string,workers:int|float,concurrency:int|float,duration_seconds:int|float,requests_total:int|float,completed_requests:int|float,successful_requests:int|float,throughput_rps:int|float,errors_total:int|float,timeouts_total:int|float,validation_failures:int|float,error_rate:int|float,cpu_percent:int|float,rss_peak_bytes:int|float,correctness_passed:bool,extension_versions:array<string,string>,latency_ms:array{p50:int|float,p95:int|float,p99:int|float}}> $records
 * @throws \RuntimeException
 */
function assertComparable(array $records): void
{
    if (count($records) < 2) {
        throw new RuntimeException('At least two evidence files are required for a comparison.');
    }

    $reference = $records[0];
    foreach (COMPARABILITY_FIELDS as $field) {
        $expected = $reference[$field];
        foreach ($records as $record) {
            if (($record[$field]) !== $expected) {
                throw new RuntimeException(sprintf('Comparative evidence mismatch for "%s".', $field));
            }
        }
    }
}

/** @param list<array{runtime:string,runtime_version:string,protocol:string,workload:string,hardware_id:string,php_version:string,instrumentation:string,host_os:string,host_cpu:string,tls:string,opcache:string,runtime_build:string,connection_reuse:string,workers:int|float,concurrency:int|float,duration_seconds:int|float,requests_total:int|float,completed_requests:int|float,successful_requests:int|float,throughput_rps:int|float,errors_total:int|float,timeouts_total:int|float,validation_failures:int|float,error_rate:int|float,cpu_percent:int|float,rss_peak_bytes:int|float,correctness_passed:bool,extension_versions:array<string,string>,latency_ms:array{p50:int|float,p95:int|float,p99:int|float}}> $records */
function renderMarkdown(array $records): string
{
    usort(
        $records,
        static fn(array $left, array $right): int => strcmp($left['runtime'], $right['runtime']),
    );

    $lines = [
        '| Runtime | Version | RPS | Successful | Timeouts | Validation failures | p50 ms | p95 ms | p99 ms | Error rate | CPU % | Peak RSS bytes |',
        '| --- | --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: |',
    ];

    foreach ($records as $record) {
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

/**
 * @param list<string> $arguments
 * @throws \RuntimeException
 */
function runComparativeEvidence(array $arguments): void
{
    $records = array_map(loadEvidence(...), array_slice($arguments, 1));
    assertComparable($records);
    fwrite(STDOUT, renderMarkdown($records));
}

if (realpath($argv[0] ?? '') === __FILE__) {
    if (!isset($argc, $argv)) {
        throw new \RuntimeException('CLI arguments are unavailable.');
    }
    runComparativeEvidence($argv);
}
