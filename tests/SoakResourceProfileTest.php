<?php

declare(strict_types=1);

require_once __DIR__ . '/../benchmarks/soak_resource_profile.php';

/** @return list<array{elapsed:float,processes:int,rss:int,virtual:int,fd:int}> */
function soakResourceTestSamples(int $rssStep = 0, int $fdStep = 0): array
{
    $samples = [];
    for ($i = 0; $i <= 120; ++$i) {
        $samples[] = [
            'elapsed' => $i * 15.0,
            'processes' => 2,
            'rss' => 100_000_000 + $i * $rssStep,
            'virtual' => 300_000_000,
            'fd' => 20 + $i * $fdStep,
        ];
    }

    return $samples;
}

it('accepts a full-duration stable resource profile', function (): void {
    $r = soakAnalyzeSamples(soakResourceTestSamples());
    expect($r['passed'])->toBeTrue()
        ->and($r['status'])->toBe('passed')
        ->and($r['duration_seconds'])->toBe(1800.0)
        ->and($r['samples'])->toBe(121);
});

it('rejects growing RSS and persistent descriptors', function (): void {
    $rss = soakAnalyzeSamples(soakResourceTestSamples(1_000_000));
    $fd = soakAnalyzeSamples(soakResourceTestSamples(0, 1));
    expect($rss['passed'])->toBeFalse()
        ->and($rss['status'])->toBe('resource_growth')
        ->and($fd['passed'])->toBeFalse()
        ->and($fd['status'])->toBe('resource_growth');
});

it('rejects truncated, nonmonotonic and incomplete samples', function (): void {
    $short = soakAnalyzeSamples(array_slice(soakResourceTestSamples(), 0, 20));
    $bad = soakResourceTestSamples();
    $bad[20]['elapsed'] = 10.0;
    expect($short['passed'])->toBeFalse()
        ->and($short['status'])->toBe('insufficient_evidence')
        ->and(fn() => soakAnalyzeSamples($bad))->toThrow(RuntimeException::class);
});


it('counts a real Linux worker process tree rather than only a root server PID', function (): void {
    if (PHP_OS_FAMILY !== 'Linux') {
        test()->markTestSkipped('Linux /proc acceptance only.');
    }

    $current = soakReadProcessTree(getmypid());
    expect($current['processes'])->toBeGreaterThanOrEqual(1)
        ->and($current['rss'])->toBeGreaterThan(0)
        ->and($current['fd'])->toBeGreaterThan(0);
});

it('persists the process-tree sample schema and rejects an omitted process column', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'runwire-soak-tree-');
    if (!is_string($path)) {
        throw new RuntimeException('Cannot create process-tree resource fixture.');
    }

    try {
        file_put_contents($path, "elapsed_seconds,process_count,rss_bytes,virtual_bytes,fd_count\n0.000,2,1000000,2000000,10\n");
        $samples = soakReadSamples($path);
        expect($samples)->toHaveCount(1)
            ->and($samples[0]['processes'])->toBe(2)
            ->and($samples[0]['fd'])->toBe(10);

        file_put_contents($path, "elapsed_seconds,rss_bytes,virtual_bytes,fd_count\n0.000,1000000,2000000,10\n");
        expect(fn() => soakReadSamples($path))->toThrow(RuntimeException::class);
    } finally {
        unlink($path);
    }
});

it('flags an increasing persistent worker process count as resource growth', function (): void {
    $samples = soakResourceTestSamples();
    foreach ($samples as $index => &$sample) {
        $sample['processes'] = 2 + intdiv($index, 20);
    }
    unset($sample);

    $result = soakAnalyzeSamples($samples);
    expect($result['passed'])->toBeFalse()
        ->and($result['status'])->toBe('resource_growth')
        ->and($result['peak_process_count'])->toBeGreaterThan(2);
});
