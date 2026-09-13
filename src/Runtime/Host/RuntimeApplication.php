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

    public function cancelActive(CancellationReason $reason = CancellationReason::HOST_CANCELLED): void
    {
        $this->lifecycle->cancelActive($reason);
    }

    public function drain(ShutdownReason $reason = ShutdownReason::SUPERVISOR_STOP): void
    {
        $this->lifecycle->drain($reason);
    }

    public function handle(
        HttpRequest $request,
        ResponseWriterInterface $writer,
        bool $completeResponse = false,
    ): void {
        $this->lifecycle->handle($request, $writer, $completeResponse);
    }

    public function shutdown(?ShutdownReason $reason = null): void
    {
        $this->lifecycle->shutdown($reason);
    }

    public function snapshot(): RuntimeMetricsSnapshot
    {
        return $this->runtimeContext->snapshot();
    }

    public function start(): void
    {
        $this->lifecycle->start();
    }
}
