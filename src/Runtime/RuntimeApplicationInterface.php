<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Runtime;

use Infocyph\Runwire\Http\HttpRequest;
use Infocyph\Runwire\Http\ResponseWriterInterface;
use Infocyph\Runwire\Metrics\MetricsProviderInterface;
use Infocyph\Runwire\Runtime\Enum\CancellationReason;
use Infocyph\Runwire\Supervisor\Enum\ShutdownReason;

interface RuntimeApplicationInterface extends MetricsProviderInterface
{
    public function cancelActive(CancellationReason $reason = CancellationReason::HOST_CANCELLED): void;

    public function drain(ShutdownReason $reason = ShutdownReason::SUPERVISOR_STOP): void;

    public function handle(
        HttpRequest $request,
        ResponseWriterInterface $writer,
        bool $completeResponse = false,
    ): void;

    public function shutdown(?ShutdownReason $reason = null): void;

    public function start(): void;
}
