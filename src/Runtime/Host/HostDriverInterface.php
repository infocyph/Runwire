<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Runtime\Host;

use Infocyph\Runwire\Runtime\RuntimeApplicationInterface;

/**
 * Defines the lifecycle contract implemented by host-owned runtime drivers.
 */
interface HostDriverInterface
{
    /**
     * Runs the supplied application within the host runtime.
     */
    public function run(RuntimeApplicationInterface $application): void;

    /**
     * Requests host runtime shutdown.
     */
    public function stop(): void;
}
