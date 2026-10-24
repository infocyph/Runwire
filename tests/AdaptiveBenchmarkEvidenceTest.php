<?php

declare(strict_types=1);

require_once __DIR__ . '/../benchmarks/adaptive_matrix_compare.php';

/** @return list<array<string, mixed>> */
function adaptiveEvidenceRecords(string $mode = 'auto'): array
{
    $phase = [
        'requests' => 18_000,
        'errors' => 0,
        'duration_seconds' => 180.0,
        'requested_duration_seconds' => 180.0,
        'concurrency' => 8,
        'throughput_rps' => 100.0,
        'p50_ms' => 1.0,
        'p95_ms' => 2.0,
        'p99_ms' => 3.0,
        'cpu_percent' => 10.0,
        'rss_peak_bytes' => 1_048_576,
        'fairness_ratio' => 1.0,
        'correctness_passed' => true,
    ];
    $record = [
        'protocol' => 'h2',
        'mode' => $mode,
        'workload' => 'test-fixture',
        'payload_bytes' => 768,
        'runtime_build' => 'fixture-build',
        'environment' => 'fixture-environment',
        'warmup_seconds' => 30.0,
        'phases' => array_fill_keys(['low_before', 'transition_up', 'high', 'transition_down', 'low_after'], $phase),
        'correctness_passed' => true,
    ];

    return array_fill(0, 5, $record);
}

it('reports sample CV and refuses noisy evidence even when medians are identical', function (): void {
    $records = adaptiveEvidenceRecords();
    foreach ([80, 90, 100, 110, 120] as $index => $rps) {
        $records[$index]['phases']['high']['throughput_rps'] = $rps;
    }
    $auto = adaptiveMatrixSummarize($records);
    $fixed = adaptiveMatrixSummarize(adaptiveEvidenceRecords('fixed'));
    $result = adaptiveMatrixCompare($auto, $fixed);

    expect($auto['phases']['high']['throughput_rps'])->toBe(100.0)
        ->and($auto['phases']['high']['throughput_rpm'])->toBe(6_000.0)
        ->and(round($auto['phases']['high']['rps_cv_percent'], 3))->toBe(15.811)
        ->and($result['measurement_qualified'])->toBeFalse()
        ->and($result['passed'])->toBeFalse();
});

it('keeps stable single workload measurements separate from full promotion certification', function (): void {
    $auto = adaptiveMatrixSummarize(adaptiveEvidenceRecords());
    $fixed = adaptiveMatrixSummarize(adaptiveEvidenceRecords('fixed'));
    $result = adaptiveMatrixCompare($auto, $fixed);

    expect($result['measurement_qualified'])->toBeTrue()
        ->and($result['passed'])->toBeTrue()
        ->and($result['promotion_certified'])->toBeFalse()
        ->and($result['scope'])->toBe('single-workload');
});

it('rejects short runs for sustained evidence without bypassing diagnostic regression budgets', function (): void {
    $auto = adaptiveMatrixSummarize(adaptiveEvidenceRecords());
    $fixed = adaptiveMatrixSummarize(adaptiveEvidenceRecords('fixed'));
    $auto['phases']['high']['duration_seconds_min'] = 7.0;
    $auto['warmup_seconds'] = $fixed['warmup_seconds'] = 1.0;

    expect(adaptiveMatrixCompare($auto, $fixed)['passed'])->toBeFalse()
        ->and(adaptiveMatrixCompare($auto, $fixed, true)['passed'])->toBeTrue()
        ->and(adaptiveMatrixCompare($auto, $fixed, true)['measurement_qualified'])->toBeFalse();

    $auto['phases']['high']['throughput_rps'] = 90.0;
    expect(adaptiveMatrixCompare($auto, $fixed, true)['passed'])->toBeFalse();
});

it('rejects missing invalid or failed phase evidence before computing statistics', function (): void {
    foreach ([null, '100', -1, INF, NAN] as $invalid) {
        $records = adaptiveEvidenceRecords();
        $records[0]['phases']['high']['throughput_rps'] = $invalid;
        expect(fn() => adaptiveMatrixSummarize($records))->toThrow(RuntimeException::class);
    }
    $records = adaptiveEvidenceRecords();
    $records[0]['phases']['high']['errors'] = 1;
    expect(fn() => adaptiveMatrixSummarize($records))->toThrow(RuntimeException::class);

    $records = adaptiveEvidenceRecords();
    $records[0]['phases']['high']['correctness_passed'] = false;
    expect(fn() => adaptiveMatrixSummarize($records))->toThrow(RuntimeException::class)
        ->and(fn() => adaptiveMatrixSummarize(array_slice($records, 0, 4)))->toThrow(RuntimeException::class);
});

it('rejects workload build and environment mismatches across trials and policies', function (): void {
    foreach (['workload', 'payload_bytes', 'runtime_build', 'environment'] as $field) {
        $records = adaptiveEvidenceRecords();
        $records[1][$field] = 'different';
        expect(fn() => adaptiveMatrixSummarize($records))->toThrow(RuntimeException::class);

        $auto = adaptiveMatrixSummarize(adaptiveEvidenceRecords());
        $fixed = adaptiveMatrixSummarize(adaptiveEvidenceRecords('fixed'));
        $fixed[$field] = 'different';
        expect(fn() => adaptiveMatrixCompare($auto, $fixed))->toThrow(RuntimeException::class);
    }
    $auto = adaptiveMatrixSummarize(adaptiveEvidenceRecords());
    $fixed = adaptiveMatrixSummarize(adaptiveEvidenceRecords('fixed'));
    unset($auto['phases']['high']['rps_cv_percent']);
    expect(fn() => adaptiveMatrixCompare($auto, $fixed))->toThrow(RuntimeException::class);
});

it('compares AUTO against each fixed profile without relabelling its mode', function (): void {
    $auto = adaptiveMatrixSummarize(adaptiveEvidenceRecords());
    foreach (['latency', 'throughput'] as $mode) {
        $baseline = adaptiveMatrixSummarize(adaptiveEvidenceRecords($mode));
        expect(adaptiveMatrixCompare($auto, $baseline, false, $mode)['passed'])->toBeTrue()
            ->and(adaptiveMatrixCompare($auto, $baseline, false, $mode)['baseline_mode'])->toBe($mode)
            ->and(fn() => adaptiveMatrixCompare($auto, $baseline))->toThrow(RuntimeException::class);
    }
});

it('requires distinct promotion trials and measures the medium phase and generator CPU', function (): void {
    $summaries = [];
    foreach (['auto', 'fixed'] as $mode) {
        $records = adaptiveEvidenceRecords($mode);
        foreach ($records as $index => &$record) {
            $record['case'] = ['id' => 'synthetic-promotion'];
            $record['trial'] = $index + 1;
            $record['phases']['medium'] = $record['phases']['high'];
            foreach ($record['phases'] as &$phase) {
                $phase['client_cpu_percent'] = 10.0;
            }
            unset($phase);
        }
        unset($record);
        $summaries[$mode] = adaptiveMatrixSummarize($records);
    }
    expect(adaptiveMatrixCompare($summaries['auto'], $summaries['fixed'])['passed'])->toBeTrue();
    $summaries['auto']['phases']['medium']['client_cpu_percent_max'] = 90.0;
    expect(adaptiveMatrixCompare($summaries['auto'], $summaries['fixed'])['measurement_qualified'])->toBeFalse();
    $records[1]['trial'] = $records[0]['trial'];
    expect(fn() => adaptiveMatrixSummarize($records))->toThrow(RuntimeException::class);
});
