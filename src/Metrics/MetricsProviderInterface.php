<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Metrics;

interface MetricsProviderInterface
{
    public function snapshot(): RuntimeMetricsSnapshot;
}
