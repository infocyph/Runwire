<?php

declare(strict_types=1);

require_once __DIR__ . '/../benchmarks/adaptive_promotion_matrix.php';

/** @return array<string, array<string, mixed>> */
function adaptivePromotionValidResults(string $protocol): array
{
    $comparison = [
        'passed' => true,
        'measurement_qualified' => true,
        'phases' => array_fill_keys(ADAPTIVE_PROMOTION_PHASES, [
            'throughput_ratio' => 1.06,
            'combined_rps_cv_percent' => 0.1,
            'p95_absolute_delta_ms' => 0.0,
            'p99_absolute_delta_ms' => 0.0,
        ]),
    ];

    $results = [];
    foreach (adaptivePromotionCases($protocol) as $case) {
        $results[$case['id']] = [
            'valid' => true,
            'comparisons' => [
                'fixed' => $comparison,
                'latency' => $comparison,
                'throughput' => $comparison,
            ],
        ];
    }

    return $results;
}

/** @return array<string, mixed> */
function adaptivePromotionFixtureRecord(array $case, string $mode, int $trial): array
{
    $phase = [
        'requests' => 18_000,
        'errors' => 0,
        'duration_seconds' => 180.0,
        'requested_duration_seconds' => 180.0,
        'concurrency' => 1,
        'throughput_rps' => $mode === 'auto' ? 106.0 : 100.0,
        'p50_ms' => 1.0,
        'p95_ms' => 2.0,
        'p99_ms' => 3.0,
        'cpu_percent' => 10.0,
        'client_cpu_percent' => 10.0,
        'rss_peak_bytes' => 1_048_576,
        'fairness_ratio' => 1.0,
        'correctness_passed' => true,
        'observed' => [],
    ];
    $phases = array_fill_keys(ADAPTIVE_PROMOTION_PHASES, $phase);
    foreach ($phases as $name => &$value) {
        $value['concurrency'] = $name === 'medium'
            ? (int) $case['medium']
            : (in_array($name, ['high', 'transition_up'], true) ? (int) $case['high'] : (int) $case['low']);
        if (str_starts_with($name, 'transition_')) {
            $value['requested_duration_seconds'] = 2.0;
            $value['duration_seconds'] = 2.0;
        }
    }
    unset($value);

    return [
        'case' => $case,
        'mode' => $mode,
        'trial' => $trial,
        'workload' => $case['id'],
        'protocol' => $case['protocol'] === 'http1' ? 'http/1.1' : $case['protocol'],
        'payload_bytes' => $case['payload_bytes'],
        'runtime_build' => 'synthetic',
        'environment' => 'test',
        'run_id' => sprintf('fixture-%s-%d', $mode, $trial),
        'warmup_seconds' => 30.0,
        'phases' => $phases,
        'correctness_passed' => true,
    ];
}

it('defines the full J10 case manifest once for all benchmark clients', function (): void {
    $h1 = adaptivePromotionCases('http1');
    $h2 = adaptivePromotionCases('h2');
    $h3 = adaptivePromotionCases('h3');

    expect([$h1, $h2, $h3])->each->toBeArray()
        ->and([count($h1), count($h2), count($h3)])->toBe([16, 28, 42])
        ->and(array_values(array_unique(array_column($h1, 'tls'))))->toHaveCount(2)
        ->and(array_values(array_unique(array_column($h1, 'keepalive'))))->toEqualCanonicalizing([8, 800])
        ->and(array_values(array_unique(array_column($h2, 'high'))))->toEqualCanonicalizing([1, 8, 32, 100])
        ->and(array_values(array_unique(array_column($h2, 'scenario'))))->toEqualCanonicalizing(['steady', 'mixed', 'flow', 'pressure'])
        ->and(array_values(array_unique(array_column($h3, 'scenario'))))->toEqualCanonicalizing(['inbound', 'outbound', 'balanced', 'qpack', 'churn']);

    foreach ([$h1, $h2, $h3] as $manifest) {
        expect(count($manifest))->toBe(count(array_unique(array_column($manifest, 'id'))))
            ->and(array_values(array_unique(array_column($manifest, 'payload_bytes'))))
            ->toEqualCanonicalizing([2, 1024, 16384, 65536]);
    }
});

it('requires complete qualified evidence and a material gain before J10 can pass', function (): void {
    foreach (['http1', 'h2', 'h3'] as $protocol) {
        $valid = adaptivePromotionValidResults($protocol);
        $result = adaptivePromotionEvaluateProtocol($valid, $protocol);

        expect($result['performance_gate_passed'])->toBeTrue()
            ->and($result['promotion_certified'])->toBeFalse();

        unset($valid[array_key_first($valid)]);
        expect(adaptivePromotionEvaluateProtocol($valid, $protocol)['performance_gate_passed'])->toBeFalse();
    }

    $evidence = adaptivePromotionValidResults('h2');
    foreach ($evidence as &$case) {
        foreach ($case['comparisons']['fixed']['phases'] as &$phase) {
            $phase['throughput_ratio'] = 1.01;
        }
        unset($phase);
    }
    unset($case);
    expect(adaptivePromotionEvaluateProtocol($evidence, 'h2')['performance_gate_passed'])->toBeFalse();
});

it('rejects transition regressions and noisy apparent gains', function (): void {
    $evidence = adaptivePromotionValidResults('h2');
    $first = array_key_first($evidence);
    $evidence[$first]['comparisons']['fixed']['phases']['transition_up']['throughput_ratio'] = 0.98;
    expect(adaptivePromotionEvaluateProtocol($evidence, 'h2')['performance_gate_passed'])->toBeFalse();

    $evidence = adaptivePromotionValidResults('h2');
    foreach ($evidence as &$case) {
        foreach ($case['comparisons']['fixed']['phases'] as &$phase) {
            $phase['combined_rps_cv_percent'] = 3.5;
        }
        unset($phase);
    }
    unset($case);
    expect(adaptivePromotionEvaluateProtocol($evidence, 'h2')['performance_gate_passed'])->toBeFalse();
});

it('validates declared H3 work instead of trusting scenario names', function (): void {
    $case = array_values(array_filter(
        adaptivePromotionCases('h3'),
        static fn(array $value): bool => $value['scenario'] === 'qpack',
    ))[0];
    $record = adaptivePromotionFixtureRecord($case, 'auto', 1);
    foreach ($record['phases'] as &$phase) {
        $phase['observed']['qpack_delayed_headers'] = 1;
    }
    unset($phase);
    $identity = ['build' => 'synthetic', 'environment_id' => 'test'];

    adaptivePromotionValidateRecord($record, $case, 'auto', 1, $identity);
    $record['phases']['high']['observed']['qpack_delayed_headers'] = 0;

    expect(fn() => adaptivePromotionValidateRecord($record, $case, 'auto', 1, $identity))
        ->toThrow(RuntimeException::class)
        ->and(fn() => adaptivePromotionValidateRecord($record, $case, 'fixed', 1, $identity))
        ->toThrow(RuntimeException::class);
});

it('recomputes promotion evidence from raw records and rejects failure markers', function (): void {
    $case = adaptivePromotionCases('h2')[0];
    $identity = ['build' => 'synthetic', 'environment_id' => 'test'];
    $directory = sys_get_temp_dir() . '/runwire-promotion-test-' . bin2hex(random_bytes(8));
    $caseDirectory = $directory . '/' . $case['id'];
    mkdir($caseDirectory, 0o777, true);

    try {
        foreach (ADAPTIVE_PROMOTION_MODES as $mode) {
            for ($trial = 1; $trial <= 5; ++$trial) {
                adaptivePromotionWriteJson(
                    sprintf('%s/%s-%d.json', $caseDirectory, $mode, $trial),
                    adaptivePromotionFixtureRecord($case, $mode, $trial),
                );
            }
        }

        $report = adaptivePromotionEvaluate($directory, 'h2', $identity);
        expect($report['cases'][$case['id']]['valid'])->toBeTrue()
            ->and($report['cases'][$case['id']]['comparisons']['latency']['passed'])->toBeTrue()
            ->and($report['performance_gate_passed'])->toBeFalse();

        adaptivePromotionWriteJson($caseDirectory . '/auto-1-failure.json', ['error' => 'fixture failure']);
        $report = adaptivePromotionEvaluate($directory, 'h2', $identity);
        expect($report['cases'][$case['id']]['valid'])->toBeFalse();
    } finally {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        if (is_dir($directory)) {
            rmdir($directory);
        }
    }
});

it('parses list/run/evaluate CLI options without allowing short sustained evidence', function (): void {
    $options = adaptivePromotionArguments([
        'adaptive_promotion_matrix.php',
        'run',
        '--protocol',
        'h2',
        '--case',
        'h2-2-s1',
        '--seconds',
        '180',
        '--warmup',
        '30',
        '--resume',
    ]);

    expect($options['protocol'])->toBe('h2')
        ->and($options['cases'])->toBe(['h2-2-s1'])
        ->and($options['seconds'])->toBe(180.0)
        ->and($options['warmup'])->toBe(30.0)
        ->and($options['resume'])->toBeTrue()
        ->and(fn() => adaptivePromotionArguments(['script', 'run', '--protocol', 'bogus']))
        ->toThrow(InvalidArgumentException::class);
});
