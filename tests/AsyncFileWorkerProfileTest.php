<?php

declare(strict_types=1);

it('profiles real process-backed file operations without certification claims', function (): void {
    $script = dirname(__DIR__) . '/benchmarks/async_file_worker_profile.php';
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' 8 2048';
    $lines = [];
    $status = 0;
    exec($cmd . ' 2>&1', $lines, $status);
    expect($status)->toBe(0, implode("\n", $lines));

    $report = json_decode(implode("\n", $lines), true, 32, JSON_THROW_ON_ERROR);
    expect($report['schemaVersion'])->toBe(1)
        ->and($report['releaseCertified'])->toBeFalse()
        ->and($report['correctnessPassed'])->toBeTrue()
        ->and($report['verifiedRoundTrips'])->toBe(8)
        ->and($report['bytesReadWritten'])->toBe(32_768)
        ->and($report['throughputBytesPerSecond'])->toBeGreaterThan(0)
        ->and($report['parentPhpPeakRssBytes'])->toBeGreaterThan(0)
        ->and($report['rssIncludesWorkerProcesses'])->toBeFalse()
        ->and($report['sampledMaxBusyWorkers'])->toBeGreaterThanOrEqual(0)
        ->and($report['sampledWorkerOccupancyRatio'])->toBeGreaterThanOrEqual(0)
        ->and($report['phpHeapPeakBytes'])->toBeGreaterThan(0)
        ->and($report['executorJobsCompleted'])->toBe(24)
        ->and($report['loopTicks'])->toBeGreaterThanOrEqual(1)
        ->and($report['executorPeakQueued'])->toBeGreaterThanOrEqual(0)
        ->and($report['executorWorkers'])->toBeGreaterThanOrEqual(1);
});

it('rejects invalid worker profiling arguments before allocating filesystem jobs', function (): void {
    $script = dirname(__DIR__) . '/benchmarks/async_file_worker_profile.php';
    foreach (['0 128', '65 128', '4 65537', '4 invalid', '4 128 0', '4 128 5', '4 128 invalid'] as $args) {
        $lines = [];
        $status = 0;
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' ' . $args . ' 2>&1', $lines, $status);
        expect($status)->not->toBe(0);
    }
});


it('profiles identical verified bytes under one and two worker processes', function (): void {
    $script = dirname(__DIR__) . '/benchmarks/async_file_worker_profile.php';
    $runs = [];
    foreach ([1, 2] as $workerCount) {
        $lines = [];
        $status = 0;
        exec(
            escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script)
            . ' 8 2048 ' . $workerCount . ' 2>&1',
            $lines,
            $status,
        );
        expect($status)->toBe(0, implode("\n", $lines));
        $runs[$workerCount] = json_decode(implode("\n", $lines), true, 32, JSON_THROW_ON_ERROR);
        expect($runs[$workerCount]['configuredWorkers'])->toBe($workerCount)
            ->and($runs[$workerCount]['verifiedRoundTrips'])->toBe(8)
            ->and($runs[$workerCount]['executorJobsCompleted'])->toBe(24)
            ->and($runs[$workerCount]['sampledMaxBusyWorkers'])->toBeLessThanOrEqual($workerCount)
            ->and($runs[$workerCount]['sampledWorkerOccupancyRatio'])->toBeLessThanOrEqual(1.0)
            ->and($runs[$workerCount]['throughputBytesPerSecond'])->toBeGreaterThan(0)
            ->and($runs[$workerCount]['loopTicks'])->toBeGreaterThan(0)
            ->and($runs[$workerCount]['releaseCertified'])->toBeFalse();
    }
    expect($runs[1]['bytesReadWritten'])->toBe($runs[2]['bytesReadWritten']);
});
