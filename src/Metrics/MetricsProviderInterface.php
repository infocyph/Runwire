<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Metrics;

/**
 * Provides immutable runtime metrics snapshots.
 */
interface MetricsProviderInterface
{
    /**
     * Capture the current runtime metrics state.
     */
    public function snapshot(): RuntimeMetricsSnapshot;
}
