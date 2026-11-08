<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Runtime\Host;

use Infocyph\Runwire\Http\HttpRequest;
use Infocyph\Runwire\Http\ResponseWriterInterface;
use Infocyph\Runwire\Metrics\RuntimeMetricsSnapshot;
use Infocyph\Runwire\Runtime\AdmissionPolicy;
use Infocyph\Runwire\Runtime\ApplicationLifecycle;
use Infocyph\Runwire\Runtime\ApplicationLifecycleHooks;
use Infocyph\Runwire\Runtime\Enum\CancellationReason;
use Infocyph\Runwire\Runtime\RequestExecutionPolicy;
use Infocyph\Runwire\Runtime\RuntimeApplicationInterface;
use Infocyph\Runwire\RuntimeContext;
use Infocyph\Runwire\Supervisor\Enum\ShutdownReason;

/**
 * Exposes an application lifecycle through the host runtime application contract.
 */
final readonly class RuntimeApplication implements RuntimeApplicationInterface
{
    private ApplicationLifecycle $lifecycle;

    private RuntimeContext $runtimeContext;

    /**
     * @param callable(HttpRequest, ResponseWriterInterface): void $handler
     * @param callable(): void|null $requestCleanup
     * @param callable(): void|null $shutdown
     */
    public function __construct(
        callable $handler,
        ?callable $requestCleanup = null,
        ?callable $shutdown = null,
        ?RuntimeContext $runtimeContext = null,
        ?RequestExecutionPolicy $requestExecution = null,
        ?ApplicationLifecycleHooks $lifecycle = null,
        ?AdmissionPolicy $admission = null,
    ) {
        $this->runtimeContext = $runtimeContext ?? RuntimeContext::standalone();
        $this->lifecycle = new ApplicationLifecycle(
            $handler,
            $this->runtimeContext,
            $requestExecution ?? new RequestExecutionPolicy(),
            $lifecycle,
            $requestCleanup,
            $shutdown,
            $admission ?? new AdmissionPolicy(),
        );
    }

    /**
     * Cancels all active application requests.
     */
    #[\Override]
    public function cancelActive(CancellationReason $reason = CancellationReason::HOST_CANCELLED): void
    {
        $this->lifecycle->cancelActive($reason);
    }

    /**
     * Starts application drain handling for the supplied shutdown reason.
     */
    #[\Override]
    public function drain(ShutdownReason $reason = ShutdownReason::SUPERVISOR_STOP): void
    {
        $this->lifecycle->drain($reason);
    }

    /**
     * Handles one HTTP request through the application lifecycle.
     * @throws \LogicException
     * @throws \Throwable
     * @throws \OverflowException
     */
    #[\Override]
    public function handle(
        HttpRequest $request,
        ResponseWriterInterface $writer,
        bool $completeResponse = false,
    ): void {
        $this->lifecycle->handle($request, $writer, $completeResponse);
    }

    /**
     * Return the first lifecycle failure that prevents safe reuse.
     */
    #[\Override]
    public function healthFailure(): ?\Throwable
    {
        return $this->lifecycle->healthFailure();
    }

    /**
     * Determine whether the lifecycle remains safe for reuse.
     */
    #[\Override]
    public function healthy(): bool
    {
        return $this->lifecycle->healthy();
    }

    /**
     * Shuts down the application lifecycle.
     * @throws \Infocyph\Runwire\Exception\ApplicationShutdownException
     */
    #[\Override]
    public function shutdown(?ShutdownReason $reason = null): void
    {
        $this->lifecycle->shutdown($reason);
    }

    /**
     * Returns the current runtime metrics snapshot.
     */
    #[\Override]
    public function snapshot(): RuntimeMetricsSnapshot
    {
        return $this->runtimeContext->snapshot();
    }

    /**
     * Starts application boot and warmup processing.
     * @throws \LogicException
     * @throws \Infocyph\Runwire\Exception\ApplicationStartupException
     */
    #[\Override]
    public function start(): void
    {
        $this->lifecycle->start();
    }
}
