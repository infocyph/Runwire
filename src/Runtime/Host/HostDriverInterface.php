<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Runtime\Host;

interface HostDriverInterface
{
    public function run(RuntimeApplication $application): void;

    public function stop(): void;
}
