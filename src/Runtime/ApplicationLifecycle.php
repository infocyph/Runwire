<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Runtime;

use Closure;
use Infocyph\Runwire\Exception\RequestLifecycleException;
use Infocyph\Runwire\Http\HttpRequest;
use Infocyph\Runwire\Http\ResponseWriterInterface;
use Infocyph\Runwire\Metrics\Enum\ApplicationErrorClass;
use Infocyph\Runwire\RequestContext;
use Infocyph\Runwire\Runtime\Enum\CancellationReason;
use Infocyph\Runwire\Runtime\Internal\AdmissionController;
use Infocyph\Runwire\RuntimeContext;
use Infocyph\Runwire\Supervisor\Enum\ShutdownReason;
use LogicException;
use Throwable;

final class ApplicationLifecycle
{
    /** @var Closure(HttpRequest, ResponseWriterInterface): void */
    private readonly Closure $handler;

    private readonly AdmissionController $admission;

    private readonly ApplicationLifecycleHooks $hooks;

    /** @var Closure(): void|null */
    private readonly ?Closure $legacyCleanup;

    /** @var Closure(): void|null */
    private readonly ?Closure $legacyShutdown;

    /** @var array<int, RequestContext> */
    private array $activeContexts = [];

    private bool $booted = false;

    private ?Throwable $drainFailure = null;

    private bool $draining = false;

    private bool $shutdown = false;

    private ShutdownReason $shutdownReason = ShutdownReason::SUPERVISOR_STOP;

    private bool $started = false;

    private bool $startupFailed = false;

    /**
     * @param callable(HttpRequest, ResponseWriterInterface): void $handler
     * @param callable(): void|null $legacyCleanup
     * @param callable(): void|null $legacyShutdown
     */
    public function __construct(
        callable $handler,
        private readonly RuntimeContext $runtimeContext,
        private readonly RequestExecutionPolicy $requestExecution = new RequestExecutionPolicy(),
        ?ApplicationLifecycleHooks $hooks = null,
        ?callable $legacyCleanup = null,
        ?callable $legacyShutdown = null,
        AdmissionPolicy $admission = new AdmissionPolicy(),
    ) {
        $this->handler = Closure::fromCallable($handler);
        $this->hooks = $hooks ?? new ApplicationLifecycleHooks();
        $this->legacyCleanup = $legacyCleanup === null ? null : Closure::fromCallable($legacyCleanup);
        $this->legacyShutdown = $legacyShutdown === null ? null : Closure::fromCallable($legacyShutdown);
        $this->admission = new AdmissionController($admission, $runtimeContext->metrics);
    }

    public function cancelActive(CancellationReason $reason = CancellationReason::HOST_CANCELLED): void
    {
        foreach ($this->activeContexts as $context) {
            $context->cancel($reason);
        }
    }

    public function drain(ShutdownReason $reason = ShutdownReason::SUPERVISOR_STOP): void
    {
        if ($this->draining || $this->shutdown || (!$this->started && !$this->booted)) {
            return;
        }

        $this->draining = true;
        $this->shutdownReason = $reason;

        try {
            ($this->hooks->drain)?->__invoke($this->runtimeContext, $reason);
        } catch (Throwable $error) {
            $this->drainFailure = $error;
        }
    }

    public function handle(
        HttpRequest $request,
        ResponseWriterInterface $writer,
        bool $completeResponse = false,
    ): void {
        if (!$this->started) {
            $this->start();
        }
        if ($this->draining || $this->shutdown) {
            throw new LogicException('Application lifecycle is draining and cannot start new request work.');
        }
        if (!$this->admission->admit($request->version)) {
            $this->admission->writeOverloadResponse($request, $writer);

            return;
        }

        try {
            $this->handleAdmitted($request, $writer, $completeResponse);
        } finally {
            $this->admission->release($request->version);
        }
    }

    public function shutdown(?ShutdownReason $reason = null): void
    {
        if ($this->shutdown || (!$this->started && !$this->booted)) {
            return;
        }

        if (!$this->draining) {
            $this->drain($reason ?? $this->shutdownReason);
        }
        $this->shutdown = true;
        if ($reason !== null) {
            $this->shutdownReason = $reason;
        }
        $this->cancelActive(CancellationReason::WORKER_SHUTDOWN);
        $failure = $this->drainFailure;

        try {
            ($this->hooks->shutdown)?->__invoke($this->runtimeContext, $this->shutdownReason);
        } catch (Throwable $error) {
            $failure ??= $error;
        }

        try {
            ($this->legacyShutdown)?->__invoke();
        } catch (Throwable $error) {
            $failure ??= $error;
        }

        if ($failure !== null) {
            throw $failure;
        }
    }

    public function start(): void
    {
        if ($this->started) {
            return;
        }
        if ($this->shutdown) {
            throw new LogicException('Application lifecycle cannot restart after shutdown.');
        }
        if ($this->startupFailed) {
            throw new LogicException('Application lifecycle startup previously failed.');
        }

        try {
            $this->booted = true;
            ($this->hooks->boot)?->__invoke($this->runtimeContext);
            ($this->hooks->warmup)?->__invoke($this->runtimeContext);
            $this->started = true;
        } catch (Throwable $error) {
            $this->startupFailed = true;
            $this->runtimeContext->metrics->recordError(ApplicationErrorClass::WARMUP_FAILURE);

            throw $error;
        }
    }

    private function handleAdmitted(
        HttpRequest $request,
        ResponseWriterInterface $writer,
        bool $completeResponse,
    ): void {
        $context = $request->context;
        $context->activate($this->runtimeContext, $this->requestExecution);
        $id = spl_object_id($context);
        $this->activeContexts[$id] = $context;
        $memoryAtStart = memory_get_usage(true);
        $this->runtimeContext->metrics->requestStarted($request->version);
        $requestFailure = null;

        try {
            ($this->handler)($request, $writer);
            if ($completeResponse && !$writer->isEnded()) {
                $writer->end();
            }
        } catch (Throwable $error) {
            $requestFailure = $error;
        }

        $resetFailures = $this->reset($context);
        unset($this->activeContexts[$id]);
        $this->runtimeContext->metrics->requestCompleted(
            $context,
            $request->version,
            $memoryAtStart,
            self::requestErrorClass($context, $requestFailure, $resetFailures),
        );
        $this->runtimeContext->metrics->maybeCollectGarbage($this->requestExecution->gc);
        $context->complete();

        if ($requestFailure !== null) {
            if ($resetFailures !== []) {
                throw new RequestLifecycleException($requestFailure, $resetFailures);
            }

            throw $requestFailure;
        }
        if ($resetFailures !== []) {
            throw new RequestLifecycleException(null, $resetFailures);
        }
    }

    /** @param list<Throwable> $resetFailures */
    private static function requestErrorClass(
        RequestContext $context,
        ?Throwable $requestFailure,
        array $resetFailures,
    ): ?ApplicationErrorClass {
        $cancellation = $context->cancellation->reason();

        return match (true) {
            $cancellation === CancellationReason::DEADLINE_EXCEEDED => ApplicationErrorClass::DEADLINE_EXCEEDED,
            $cancellation === CancellationReason::TRANSPORT_CANCELLED => ApplicationErrorClass::CLIENT_CANCELLED,
            $cancellation === CancellationReason::HOST_CANCELLED,
            $cancellation === CancellationReason::WORKER_SHUTDOWN => ApplicationErrorClass::TRANSPORT_ERROR,
            $requestFailure !== null => ApplicationErrorClass::HANDLER_EXCEPTION,
            $resetFailures !== [] => ApplicationErrorClass::RESETTER_FAILURE,
            default => null,
        };
    }

    /** @return list<Throwable> */
    private function reset(RequestContext $context): array
    {
        $failures = $this->hooks->resetters->reset($context);
        if ($this->legacyCleanup === null) {
            return $failures;
        }

        try {
            ($this->legacyCleanup)();
        } catch (Throwable $error) {
            $failures[] = $error;
        }

        return $failures;
    }
}
