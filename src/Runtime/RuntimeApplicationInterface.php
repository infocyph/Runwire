<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Runtime;

use Infocyph\Runwire\Http\HttpRequest;
use Infocyph\Runwire\Http\ResponseWriterInterface;
use Infocyph\Runwire\Metrics\MetricsProviderInterface;
use Infocyph\Runwire\Runtime\Enum\CancellationReason;
use Infocyph\Runwire\Supervisor\Enum\ShutdownReason;

/**
 * Defines the lifecycle and request-handling contract for runtime applications.
 */
interface RuntimeApplicationInterface extends MetricsProviderInterface
{
    /**
     * Cancels all active requests with the supplied reason.
     */
    public function cancelActive(CancellationReason $reason = CancellationReason::HOST_CANCELLED): void;

    /**
     * Begins application drain processing.
     */
    public function drain(ShutdownReason $reason = ShutdownReason::SUPERVISOR_STOP): void;

    /**
     * Handles one HTTP request and response writer pair.
     */
    public function handle(
        HttpRequest $request,
        ResponseWriterInterface $writer,
        bool $completeResponse = false,
    ): void;

    /**
     * Shuts down application resources.
     */
    public function shutdown(?ShutdownReason $reason = null): void;

    /**
     * Starts application boot and warmup processing.
     */
    public function start(): void;
}
