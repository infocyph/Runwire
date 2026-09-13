<?php

declare(strict_types=1);

namespace Infocyph\Runwire;

use Infocyph\Runwire\Runtime\Enum\OpcacheMode;
use Infocyph\Runwire\Runtime\Enum\RuntimeDriver;
use Infocyph\Runwire\Runtime\RequestExecutionPolicy;
use Infocyph\Runwire\Supervisor\WorkerRecyclePolicy;

final readonly class RuntimeOptions
{
    public function __construct(
        public RuntimeDriver $driver = RuntimeDriver::AUTO,
        public OpcacheMode $opcache = OpcacheMode::AUTO,
        public WorkerRecyclePolicy $workerRecycle = new WorkerRecyclePolicy(),
        public RequestExecutionPolicy $requestExecution = new RequestExecutionPolicy(),
        public FpmOptions $fpm = new FpmOptions(),
        public FrankenPhpOptions $frankenPhp = new FrankenPhpOptions(),
        public RoadRunnerOptions $roadRunner = new RoadRunnerOptions(),
        public SwooleOptions $swoole = new SwooleOptions(),
    ) {}
}
