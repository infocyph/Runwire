<?php

declare(strict_types=1);

/** @return array<string, mixed> */
function releaseCompareFixture(string $build, float $successfulRps = 1_000.0, float $cv = 1.0): array
{
    return [
        'protocol' => 'http1',
        'workload' => 'release-native',
        'php_version' => '8.5.11',
        'hardware_id' => 'matched-runner',
        'host_os' => 'Linux',
        'host_cpu' => 'identical-cpu',
        'instrumentation' => 'off',
        'tls' => 'none',
        'opcache' => 'enabled',
        'connection_reuse' => 'keepalive',
        'runtime_build' => $build,
        'extension_versions' => ['event' => '3.1.4'],
        'trials' => 5,
        'duration_seconds_per_trial' => 180,
        'workers' => 1,
        'concurrency' => 16,
        'median_successful_rps' => $successfulRps,
        'median_successful_rpm' => $successfulRps * 60,
        'rps_cv_percent' => $cv,
        'median_p99_ms' => 2.0,
        'peak_rss_bytes' => 120_000_000,
        'correctness_passed' => true,
    ];
}

/** @return array{exit: int, report: array<string, mixed>, stderr: string} */
function releaseCompareRun(array $baseline, array $candidate): array
{
    $baselinePath = tempnam(sys_get_temp_dir(), 'runwire-baseline-');
    $candidatePath = tempnam(sys_get_temp_dir(), 'runwire-candidate-');
    if (!is_string($baselinePath) || !is_string($candidatePath)) {
        throw new RuntimeException('Failed to create paired benchmark fixture files.');
    }

    try {
        file_put_contents($baselinePath, json_encode($baseline, JSON_THROW_ON_ERROR));
        file_put_contents($candidatePath, json_encode($candidate, JSON_THROW_ON_ERROR));
        $process = proc_open(
            [PHP_BINARY, __DIR__ . '/../benchmarks/regression_compare.php', $baselinePath, $candidatePath],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to invoke Runwire benchmark comparator.');
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        if (!is_string($stdout) || !is_string($stderr)) {
            throw new RuntimeException('Benchmark comparator did not produce readable output.');
        }

        return [
            'exit' => $exit,
            'report' => json_decode($stdout, true, flags: JSON_THROW_ON_ERROR),
            'stderr' => $stderr,
        ];
    } finally {
        @unlink($baselinePath);
        @unlink($candidatePath);
    }
}

it('accepts valid matched performance evidence with stable variance', function (): void {
    $run = releaseCompareRun(
        releaseCompareFixture('2.1.2'),
        releaseCompareFixture('candidate', 1_015.0),
    );

    expect($run['exit'])->toBe(0)
        ->and($run['report']['passed'])->toBeTrue()
        ->and($run['report']['budget_enforced'])->toBeTrue()
        ->and($run['report']['certification_status'])->toBe('passed');
});

it('rejects high-variance evidence even when the median throughput improved', function (): void {
    $run = releaseCompareRun(
        releaseCompareFixture('2.1.2', 1_000.0, 4.8),
        releaseCompareFixture('candidate', 1_020.0, 3.9),
    );

    expect($run['exit'])->not->toBe(0)
        ->and($run['report']['passed'])->toBeFalse()
        ->and($run['report']['budget_enforced'])->toBeFalse()
        ->and($run['report']['certification_status'])->toBe('inconclusive_variance')
        ->and($run['stderr'])->toContain('inconclusive');
});

it('rejects an excessive throughput regression with stable variance', function (): void {
    $run = releaseCompareRun(
        releaseCompareFixture('2.1.2'),
        releaseCompareFixture('candidate', 930.0),
    );

    expect($run['exit'])->not->toBe(0)
        ->and($run['report']['passed'])->toBeFalse()
        ->and($run['report']['budget_enforced'])->toBeTrue()
        ->and($run['report']['throughput_passed'])->toBeFalse()
        ->and($run['report']['certification_status'])->toBe('failed');
});
