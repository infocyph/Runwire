<?php

declare(strict_types=1);

it('rejects short diagnostic trials as matched release certification', function (): void {
    $root = dirname(__DIR__);
    $script = $root . '/benchmarks/regression_compare.php';
    $summary = [
        'protocol' => 'http/1.1',
        'workload' => 'matched',
        'php_version' => PHP_VERSION,
        'hardware_id' => 'fixture-hardware',
        'host_os' => PHP_OS_FAMILY,
        'host_cpu' => 'fixture-cpu',
        'instrumentation' => 'matched',
        'tls' => 'none',
        'opcache' => 'enabled-cli',
        'connection_reuse' => 'keepalive',
        'runtime_build' => str_repeat('a', 40),
        'trials' => 5,
        'duration_seconds_per_trial' => 3,
        'workers' => 1,
        'concurrency' => 16,
        'median_successful_rps' => 1000.0,
        'median_successful_rpm' => 60000.0,
        'rps_cv_percent' => 0.5,
        'median_p99_ms' => 5.0,
        'peak_rss_bytes' => 1048576,
        'correctness_passed' => true,
        'extension_versions' => ['pcntl' => 'builtin'],
    ];

    $baseline = tempnam(sys_get_temp_dir(), 'runwire-baseline-');
    $candidate = tempnam(sys_get_temp_dir(), 'runwire-candidate-');
    expect($baseline)->toBeString()->and($candidate)->toBeString();
    try {
        file_put_contents($baseline, json_encode($summary, JSON_THROW_ON_ERROR));
        $summary['runtime_build'] = str_repeat('b', 40);
        file_put_contents($candidate, json_encode($summary, JSON_THROW_ON_ERROR));
        $command = [PHP_BINARY, $script, $baseline, $candidate];
        $process = proc_open($command, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);
        expect(is_resource($process))->toBeTrue();
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        expect($exit)->not->toBe(0)
            ->and($stderr)->toContain('invalid measurement ranges')
            ->and($stdout)->toBe('');
    } finally {
        unlink($baseline);
        unlink($candidate);
    }
});
