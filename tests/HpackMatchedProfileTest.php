<?php

declare(strict_types=1);

it('emits bounded HPACK source-only comparable microprofiles without claiming certification', function (): void {
    $root = dirname(__DIR__);
    $source = $root . '/src';
    $script = $root . '/benchmarks/u08_hpack_source_profile.php';
    $revision = str_repeat('a', 40);
    $command = escapeshellarg(PHP_BINARY)
        . ' ' . escapeshellarg($script)
        . ' ' . escapeshellarg($source)
        . ' ' . escapeshellarg($revision)
        . ' 16';
    $output = [];
    $status = 0;
    exec($command . ' 2>&1', $output, $status);
    expect($status)->toBe(0, implode("\n", $output));

    $profile = json_decode(implode("\n", $output), true, 32, JSON_THROW_ON_ERROR);
    expect($profile['schemaVersion'])->toBe(1)
        ->and($profile['sourceRevision'])->toBe($revision)
        ->and($profile['releaseCertified'])->toBeFalse()
        ->and(array_keys($profile['workloads']))->toBe([
            'integer_continuation',
            'huffman_bounded_decode',
            'http2_header_validation',
            'header_frame_write_churn',
        ]);
    foreach ($profile['workloads'] as $case) {
        expect($case['iterations'])->toBe(16)
            ->and($case['trials'])->toBe(5)
            ->and($case['median_nanoseconds_per_op'])->toBeGreaterThan(0)
            ->and($case['cv_percent'])->toBeGreaterThanOrEqual(0);
    }

    $bad = [];
    $badStatus = 0;
    exec(
        escapeshellarg(PHP_BINARY)
        . ' ' . escapeshellarg($script)
        . ' ' . escapeshellarg($source)
        . ' ' . escapeshellarg('not-a-commit') . ' 16 2>&1',
        $bad,
        $badStatus,
    );
    expect($badStatus)->not->toBe(0);
});
