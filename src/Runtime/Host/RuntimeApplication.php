<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Runtime\Host;

use Closure;
use Infocyph\Runwire\Http\HttpRequest;
use Infocyph\Runwire\Http\ResponseWriterInterface;
use Infocyph\Runwire\RequestContext;
use Infocyph\Runwire\Runtime\Enum\CancellationReason;
use Infocyph\Runwire\Runtime\RequestExecutionPolicy;
use Infocyph\Runwire\RuntimeContext;

final class RuntimeApplication
{
    /** @var array<int, RequestContext> */
    private array $activeContexts = [];

    /** @var Closure(HttpRequest, ResponseWriterInterface): void */
    private readonly Closure $handler;

    /** @var Closure(): void|null */
    private readonly ?Closure $requestCleanup;

    private readonly RequestExecutionPolicy $requestExecution;

    private readonly RuntimeContext $runtimeContext;

    /** @var Closure(): void|null */
    private readonly ?Closure $shutdownCallback;

    private bool $shutdown = false;

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
    ) {
        $this->handler = Closure::fromCallable($handler);
        $this->requestCleanup = $requestCleanup === null ? null : Closure::fromCallable($requestCleanup);
        $this->shutdownCallback = $shutdown === null ? null : Closure::fromCallable($shutdown);
        $this->runtimeContext = $runtimeContext ?? RuntimeContext::standalone();
        $this->requestExecution = $requestExecution ?? new RequestExecutionPolicy();
    }

    public function cancelActive(CancellationReason $reason = CancellationReason::HOST_CANCELLED): void
    {
        foreach ($this->activeContexts as $context) {
            $context->cancel($reason);
        }
    }

    public function handle(HttpRequest $request, ResponseWriterInterface $writer): void
    {
        $context = $request->context;
        $context->activate($this->runtimeContext, $this->requestExecution);
        $id = spl_object_id($context);
        $this->activeContexts[$id] = $context;

        try {
            ($this->handler)($request, $writer);
        } finally {
            try {
                ($this->requestCleanup)?->__invoke();
            } finally {
                unset($this->activeContexts[$id]);
                $context->complete();
            }
        }
    }

    public function shutdown(): void
    {
        if ($this->shutdown) {
            return;
        }

        $this->shutdown = true;
        $this->cancelActive(CancellationReason::WORKER_SHUTDOWN);
        ($this->shutdownCallback)?->__invoke();
    }
}
