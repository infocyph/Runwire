<?php

declare(strict_types=1);

use Infocyph\Runwire\Metrics\DiagnosticsPolicy;

it('rejects nonfinite, nonpositive and oversized diagnostics thresholds', function (): void {
    expect(fn() => new DiagnosticsPolicy(busyWorkerThresholdSeconds: 0.0))
        ->toThrow(InvalidArgumentException::class);
    expect(fn() => new DiagnosticsPolicy(busyWorkerThresholdSeconds: INF))
        ->toThrow(InvalidArgumentException::class);
    expect(fn() => new DiagnosticsPolicy(callbackOverrunSeconds: 60.1))
        ->toThrow(InvalidArgumentException::class);
    expect(fn() => new DiagnosticsPolicy(workerReportIntervalSeconds: NAN))
        ->toThrow(InvalidArgumentException::class);
    expect(new DiagnosticsPolicy()->busyWorkerThresholdSeconds)->toBe(30.0);
});
