<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Metrics;

use InvalidArgumentException;

/**
 * Defines runtime diagnostics thresholds and worker reporting cadence.
 */
final readonly class DiagnosticsPolicy
{
    /**
     * Create and validate diagnostics policy values.
     */
    public function __construct(
        public bool $debug = false,
        public float $busyWorkerThresholdSeconds = 30.0,
        public float $callbackOverrunSeconds = 0.05,
        public float $workerReportIntervalSeconds = 0.25,
    ) {
        self::positiveBounded($busyWorkerThresholdSeconds, 86_400.0, 'Busy-worker threshold');
        self::positiveBounded($callbackOverrunSeconds, 60.0, 'Callback-overrun threshold');
        self::positiveBounded($workerReportIntervalSeconds, 60.0, 'Worker metrics report interval');
    }

    private static function positiveBounded(float $value, float $maximum, string $label): void
    {
        if (!is_finite($value) || $value <= 0 || $value > $maximum) {
            throw new InvalidArgumentException(sprintf('%s must be finite and between 0 and %.0f seconds.', $label, $maximum));
        }
    }
}
