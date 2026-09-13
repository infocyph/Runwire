<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Runtime\Internal;

use Infocyph\Runwire\Loop\LoopDiagnosticsProviderInterface;
use Infocyph\Runwire\Metrics\DiagnosticsPolicy;
use Infocyph\Runwire\Metrics\RuntimeMetrics;
use Infocyph\Runwire\Supervisor\WorkerContext;

final class WorkerDiagnosticsSampler
{
    private const int NANOS_PER_SECOND = 1_000_000_000;

    private readonly int $sampleIntervalNanoseconds;

    private int $lastSampleNanoseconds = 0;

    public function __construct(
        private readonly WorkerContext $context,
        private readonly RuntimeMetrics $metrics,
        DiagnosticsPolicy $policy,
        private readonly ?LoopDiagnosticsProviderInterface $loop = null,
    ) {
        $this->sampleIntervalNanoseconds = (int) round(
            $policy->workerReportIntervalSeconds * self::NANOS_PER_SECOND,
        );
    }

    public function sample(bool $force = false): void
    {
        $now = self::nowNanoseconds();
        if (!$force && $now - $this->lastSampleNanoseconds < $this->sampleIntervalNanoseconds) {
            return;
        }

        if ($this->loop !== null) {
            $this->metrics->observeLoop($this->loop->diagnostics());
        }

        $this->context->reportMetrics($this->metrics->snapshot());
        $this->lastSampleNanoseconds = $now;
    }

    private static function nowNanoseconds(): int
    {
        $now = hrtime(true);

        return is_int($now) ? $now : (int) $now;
    }
}
