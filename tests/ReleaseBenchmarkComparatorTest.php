<?php

declare(strict_types=1);

/** @return array<string, mixed> */
function runwireReleaseSummaryFixture(): array
{
    return [
        'runtime_build' => 'baseline-test',
        'protocol' => 'http/1.1',
        'workload' => 'empty-payload',
        'php_version' => '8.5.0',
        'hardware_id' => 'test-machine',
        'host_os' => 'Linux',
        'host_cpu' => 'test-cpu',
        'instrumentation' => 'release-certification-matched',
        'tls' => 'off',
        'opcache' => 'enabled-cli',
        'connection_reuse' => 'keep-alive',
        'extension_versions' => ['pcntl' => 'builtin', 'event' => '3.1.0'],
        'trials' => 5,
        'duration_seconds_per_trial' => 180.0,
        'workers' => 1,
        'concurrency' => 16,
        'median_successful_rps' => 1000.0,
        'median_successful_rpm' => 60000.0,
        'rps_cv_percent' => 0.5,
        'median_p99_ms' => 5.0,
        'peak_rss_bytes' => 12_000_000,
        'correctness_passed' => true,
    ];
}

/** @return array{int, string} */
function runwireCompareReleaseSummaries(array $baseline, array $candidate): array
{
    $first = tempnam(sys_get_temp_dir(), 'runwire-baseline-');
    $second = tempnam(sys_get_temp_dir(), 'runwire-candidate-');
    if (!is_string($first) || !is_string($second)) {
        throw new RuntimeException('Cannot create comparison fixtures.');
    }
    try {
        file_put_contents($first, json_encode($baseline, JSON_THROW_ON_ERROR));
        file_put_contents($second, json_encode($candidate, JSON_THROW_ON_ERROR));
        $process = proc_open(
            [PHP_BINARY, __DIR__ . '/../benchmarks/regression_compare.php', $first, $second],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Cannot start regression comparator.');
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        return [proc_close($process), (string) $stdout . (string) $stderr];
    } finally {
        unlink($first);
        unlink($second);
    }
}

it('compares a stable, identical release topology and reports p99/RSS deltas', function (): void {
    $baseline = runwireReleaseSummaryFixture();
    $candidate = $baseline;
    $candidate['runtime_build'] = 'candidate-test';
    $candidate['median_successful_rps'] = 1020.0;
    $candidate['median_successful_rpm'] = 61200.0;
    $candidate['median_p99_ms'] = 5.7;
    $candidate['peak_rss_bytes'] = 13_200_000;

    [$exit, $json] = runwireCompareReleaseSummaries($baseline, $candidate);
    $result = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
    expect($exit)->toBe(0)
        ->and($result['passed'])->toBeTrue()
        ->and($result['budget_enforced'])->toBeTrue()
        ->and($result['delta_percent'])->toBe(2)
        ->and($result['p99_delta_percent'])->toBe(14)
        ->and($result['p99_passed'])->toBeTrue()
        ->and($result['rss_passed'])->toBeTrue()
        ->and($result['rss_delta_percent'])->toBe(10);
});

it('rejects mismatched release topologies rather than comparing incomparable results', function (): void {
    foreach ([
        'workers' => 2,
        'concurrency' => 32,
        'duration_seconds_per_trial' => 30.0,
        'tls' => 'on',
        'opcache' => 'disabled',
        'extension_versions' => ['event' => 'different'],
        'host_cpu' => 'another-cpu',
    ] as $field => $altered) {
        $baseline = runwireReleaseSummaryFixture();
        $candidate = $baseline;
        $candidate['runtime_build'] = 'candidate-test';
        $candidate[$field] = $altered;
        [$exit, $message] = runwireCompareReleaseSummaries($baseline, $candidate);
        expect($exit)->toBeGreaterThan(0)
            ->and($message)->toContain(sprintf('mismatch for "%s"', $field));
    }
});

it('rejects a stable throughput regression and cannot certify unstable evidence', function (): void {
    $baseline = runwireReleaseSummaryFixture();
    $candidate = $baseline;
    $candidate['runtime_build'] = 'candidate-test';
    $candidate['median_successful_rps'] = 900.0;
    [$exit, $output] = runwireCompareReleaseSummaries($baseline, $candidate);
    expect($exit)->toBeGreaterThan(0)
        ->and($output)->toContain('exceeds the permitted throughput, p99 latency, or RSS regression budget');

    $candidate = $baseline;
    $candidate['runtime_build'] = 'candidate-test';
    $candidate['rps_cv_percent'] = 6.0;
    [$exit, $output] = runwireCompareReleaseSummaries($baseline, $candidate);
    expect($exit)->toBeGreaterThan(0)
        ->and($output)->toContain('"budget_enforced": false')
        ->and($output)->toContain('"passed": false')
        ->and($output)->toContain('"certification_status": "inconclusive_variance"')
        ->and($output)->toContain('inconclusive');
});

it('fails closed on missing or non-finite release benchmark metrics', function (): void {
    foreach (['median_p99_ms', 'peak_rss_bytes', 'duration_seconds_per_trial'] as $field) {
        $baseline = runwireReleaseSummaryFixture();
        $candidate = $baseline;
        unset($candidate[$field]);
        [$exit] = runwireCompareReleaseSummaries($baseline, $candidate);
        expect($exit)->toBeGreaterThan(0);
    }
});

it('rejects p99 and RSS regressions even when successful throughput is unchanged', function (): void {
    $baseline = runwireReleaseSummaryFixture();

    $slow = $baseline;
    $slow['runtime_build'] = 'slow-candidate';
    $slow['median_p99_ms'] = 6.0; // +20%, +1ms exceeds both latency tolerances.
    [$exit, $output] = runwireCompareReleaseSummaries($baseline, $slow);
    expect($exit)->toBeGreaterThan(0)
        ->and($output)->toContain('"p99_passed": false')
        ->and($output)->toContain('"throughput_passed": true');

    $large = $baseline;
    $large['runtime_build'] = 'large-candidate';
    $large['peak_rss_bytes'] = 18_000_000; // Exceeds 110% of 12MB plus 4MiB.
    [$exit, $output] = runwireCompareReleaseSummaries($baseline, $large);
    expect($exit)->toBeGreaterThan(0)
        ->and($output)->toContain('"rss_passed": false')
        ->and($output)->toContain('"throughput_passed": true');
});

it('keeps the p99 absolute tolerance for fast workloads without dropping the RSS guard', function (): void {
    $baseline = runwireReleaseSummaryFixture();
    $baseline['median_p99_ms'] = 0.5;
    $candidate = $baseline;
    $candidate['runtime_build'] = 'small-latency-delta';
    $candidate['median_p99_ms'] = 0.9; // +80%, but only +0.4ms.
    [$exit, $output] = runwireCompareReleaseSummaries($baseline, $candidate);
    $result = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
    expect($exit)->toBe(0)
        ->and($result['p99_passed'])->toBeTrue()
        ->and($result['rss_passed'])->toBeTrue();
});

it('rejects tail-latency breaches even when throughput noise prevents a rate verdict', function (): void {
    $baseline = runwireReleaseSummaryFixture();
    $candidate = $baseline;
    $candidate['runtime_build'] = 'noisy-tail';
    $candidate['rps_cv_percent'] = 10.0;
    $candidate['median_p99_ms'] = 10.0;
    [$exit, $output] = runwireCompareReleaseSummaries($baseline, $candidate);
    expect($exit)->toBeGreaterThan(0)
        ->and($output)->toContain('"budget_enforced": false')
        ->and($output)->toContain('"p99_passed": false');
});
