<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Runtime\Host;

use Infocyph\Runwire\Http\HttpRequest;
use Infocyph\Runwire\Http\ResponseWriterInterface;
use Infocyph\Runwire\Runtime\ApplicationLifecycle;
use Infocyph\Runwire\Runtime\ApplicationLifecycleHooks;
use Infocyph\Runwire\Runtime\Enum\CancellationReason;
use Infocyph\Runwire\Runtime\RequestExecutionPolicy;
use Infocyph\Runwire\RuntimeContext;

final readonly class RuntimeApplication
{
    private ApplicationLifecycle $lifecycle;

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
    ) {
        $this->lifecycle = new ApplicationLifecycle(
            $handler,
            $runtimeContext ?? RuntimeContext::standalone(),
            $requestExecution ?? new RequestExecutionPolicy(),
            $lifecycle,
            $requestCleanup,
            $shutdown,
        );
    }

    public function cancelActive(CancellationReason $reason = CancellationReason::HOST_CANCELLED): void
    {
        $this->lifecycle->cancelActive($reason);
    }

    public function drain(): void
    {
        $this->lifecycle->drain();
    }

    public function handle(
        HttpRequest $request,
        ResponseWriterInterface $writer,
        bool $completeResponse = false,
    ): void {
        $this->lifecycle->handle($request, $writer, $completeResponse);
    }

    public function shutdown(): void
    {
        $this->lifecycle->shutdown();
    }

    public function start(): void
    {
        $this->lifecycle->start();
    }
}
