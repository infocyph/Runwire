<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Runtime\Host;

use Infocyph\Runwire\Runtime\RuntimeApplicationInterface;

interface HostDriverInterface
{
    public function run(RuntimeApplicationInterface $application): void;

    public function stop(): void;
}
