<?php

declare(strict_types=1);

it('profiles equivalent QPACK and QUIC varint source workloads without release claims', function (): void {
    $root = dirname(__DIR__);
    $script = $root . '/benchmarks/u08_qpack_source_profile.php';
    $source = $root . '/src';
    $revision = str_repeat('b', 40);
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
        ->and($profile['nativeQuicPollMeasured'])->toBeFalse()
        ->and(array_keys($profile['workloads']))->toBe([
            'qpack_continuation_integer',
            'quic_varint_widths',
            'qpack_dynamic_block_unblock',
        ]);
    foreach ($profile['workloads'] as $sample) {
        expect($sample['trials'])->toBe(5)
            ->and($sample['iterations'])->toBe(16)
            ->and($sample['median_nanoseconds_per_op'])->toBeGreaterThan(0)
            ->and($sample['cv_percent'])->toBeGreaterThanOrEqual(0);
    }

    foreach (['invalid', str_repeat('c', 39), str_repeat('d', 41)] as $badRevision) {
        $invalid = [];
        $exit = 0;
        exec(
            escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script)
            . ' ' . escapeshellarg($source) . ' ' . escapeshellarg($badRevision)
            . ' 16 2>&1',
            $invalid,
            $exit,
        );
        expect($exit)->not->toBe(0);
    }
});
