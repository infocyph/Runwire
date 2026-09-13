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
];

const REQUIRED_NUMERIC_FIELDS = [
    'workers',
    'concurrency',
    'duration_seconds',
    'throughput_rps',
    'errors_total',
    'error_rate',
    'cpu_percent',
    'rss_peak_bytes',
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
        if (!isset($decoded[$field]) || !is_string($decoded[$field]) || $decoded[$field] === '') {
            throw new RuntimeException(sprintf('Evidence file "%s" is missing string field "%s".', $path, $field));
        }
    }
    foreach (REQUIRED_NUMERIC_FIELDS as $field) {
        if (!isset($decoded[$field]) || !is_numeric($decoded[$field])) {
            throw new RuntimeException(sprintf('Evidence file "%s" is missing numeric field "%s".', $path, $field));
        }
    }

    $latency = $decoded['latency_ms'] ?? null;
    if (!is_array($latency)) {
        throw new RuntimeException(sprintf('Evidence file "%s" is missing latency_ms.', $path));
    }
    foreach (['p50', 'p95', 'p99'] as $field) {
        if (!isset($latency[$field]) || !is_numeric($latency[$field])) {
            throw new RuntimeException(sprintf('Evidence file "%s" is missing latency_ms.%s.', $path, $field));
        }
    }

    return $decoded;
}

/** @param list<array<string, mixed>> $records */
function assertComparable(array $records): void
{
    if (count($records) < 2) {
        throw new RuntimeException('At least two evidence files are required for a comparison.');
    }

    $reference = $records[0];
    foreach (['hardware_id', 'php_version', 'protocol', 'workload', 'workers', 'concurrency', 'duration_seconds'] as $field) {
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
        '| Runtime | Version | Instrumentation | RPS | p50 ms | p95 ms | p99 ms | Errors | Error rate | CPU % | Peak RSS bytes |',
        '| --- | --- | --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: |',
    ];

    foreach ($records as $record) {
        /** @var array<string, int|float> $latency */
        $latency = $record['latency_ms'];
        $lines[] = sprintf(
            '| %s | %s | %s | %.2f | %.3f | %.3f | %.3f | %d | %.6f | %.2f | %d |',
            $record['runtime'],
            $record['runtime_version'],
            $record['instrumentation'],
            (float) $record['throughput_rps'],
            (float) $latency['p50'],
            (float) $latency['p95'],
            (float) $latency['p99'],
            (int) $record['errors_total'],
            (float) $record['error_rate'],
            (float) $record['cpu_percent'],
            (int) $record['rss_peak_bytes'],
        );
    }

    return implode(PHP_EOL, $lines) . PHP_EOL;
}

try {
    $paths = array_slice($argv, 1);
    $records = array_map(loadEvidence(...), $paths);
    assertComparable($records);
    fwrite(STDOUT, renderMarkdown($records));
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);

    return 1;
}
