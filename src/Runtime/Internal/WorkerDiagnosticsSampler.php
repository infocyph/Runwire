<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Runtime\Internal;

use Infocyph\Runwire\Internal\MonotonicTime;
use Infocyph\Runwire\Loop\LoopDiagnosticsProviderInterface;
use Infocyph\Runwire\Metrics\DiagnosticsPolicy;
use Infocyph\Runwire\Metrics\RuntimeMetrics;
use Infocyph\Runwire\Supervisor\WorkerContext;

/**
 * Periodically reports runtime and loop diagnostics through a worker context.
 */
final class WorkerDiagnosticsSampler
{
    private readonly int $sampleIntervalNanoseconds;

    private int $lastSampleNanoseconds = 0;

    /**
     * Creates a diagnostics sampler using the configured report interval.
     */
    public function __construct(
        private readonly WorkerContext $context,
        private readonly RuntimeMetrics $metrics,
        DiagnosticsPolicy $policy,
        private readonly ?LoopDiagnosticsProviderInterface $loop = null,
    ) {
        $this->sampleIntervalNanoseconds = MonotonicTime::secondsToNanoseconds(
            $policy->workerReportIntervalSeconds,
        );
    }

    /**
     * Reports a metrics sample when due or when forced.
     */
    public function sample(bool $force = false): void
    {
        $now = MonotonicTime::nowNanoseconds();
        if (!$force && $now - $this->lastSampleNanoseconds < $this->sampleIntervalNanoseconds) {
            return;
        }

        if ($this->loop !== null) {
            $this->metrics->observeLoop($this->loop->diagnostics());
        }

        $this->context->reportMetrics($this->metrics->snapshot());
        $this->lastSampleNanoseconds = $now;
    }
}
