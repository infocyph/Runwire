<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Runtime;

use Closure;
use Infocyph\Runwire\Exception\ApplicationShutdownException;
use Infocyph\Runwire\Exception\ApplicationStartupException;
use Infocyph\Runwire\Http\HttpRequest;
use Infocyph\Runwire\Http\ResponseWriterInterface;
use Infocyph\Runwire\Metrics\Enum\ApplicationErrorClass;
use Infocyph\Runwire\RequestContext;
use Infocyph\Runwire\Runtime\Enum\ApplicationStartupPhase;
use Infocyph\Runwire\Runtime\Enum\CancellationReason;
use Infocyph\Runwire\Runtime\Internal\AdmissionController;
use Infocyph\Runwire\Runtime\Internal\RequestFinalizer;
use Infocyph\Runwire\RuntimeContext;
use Infocyph\Runwire\Supervisor\Enum\ShutdownReason;
use LogicException;
use Throwable;

/**
 * Coordinates application startup, admission, request cleanup, draining, and shutdown.
 */
final class ApplicationLifecycle
{
    private readonly AdmissionController $admission;

    /** @var Closure(HttpRequest, ResponseWriterInterface): void */
    private readonly Closure $handler;

    private readonly ApplicationLifecycleHooks $hooks;

    /** @var Closure(): void|null */
    private readonly ?Closure $legacyCleanup;

    /** @var Closure(): void|null */
    private readonly ?Closure $legacyShutdown;

    /** @var array<int, RequestContext> */
    private array $activeContexts = [];

    private bool $booted = false;

    /** @var list<Throwable> */
    private array $drainFailures = [];

    private bool $draining = false;

    private ?Throwable $healthFailure = null;

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

    /**
     * Cancels every active request context with the supplied reason.
     */
    public function cancelActive(CancellationReason $reason = CancellationReason::HOST_CANCELLED): void
    {
        foreach ($this->activeContexts as $context) {
            $context->cancel($reason);
        }
    }

    /**
     * Starts application drain processing and rejects new request work.
     */
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
            $this->drainFailures[] = $error;
        }
    }

    /**
     * Executes one admitted request through the application handler and lifecycle cleanup.
     */
    public function handle(
        HttpRequest $request,
        ResponseWriterInterface $writer,
        bool $completeResponse = false,
    ): void {
        if (!$this->started) {
            $this->start();
        }
        if ($this->healthFailure !== null) {
            throw new LogicException('Application lifecycle is unhealthy and cannot start new request work.', 0, $this->healthFailure);
        }
        if ($this->draining || $this->shutdown) {
            throw new LogicException('Application lifecycle is draining and cannot start new request work.');
        }
        if (!$this->admission->admit($request->version)) {
            try {
                $this->admission->writeOverloadResponse($request, $writer);
            } finally {
                $request->context->complete();
            }

            return;
        }

        $this->handleAdmitted($request, $writer, $completeResponse);
    }

    /**
     * Return the first cleanup failure that made this lifecycle unsafe for reuse.
     */
    public function healthFailure(): ?Throwable
    {
        return $this->healthFailure;
    }

    /**
     * Determine whether the application can safely admit another request.
     */
    public function healthy(): bool
    {
        return $this->healthFailure === null;
    }

    /**
     * Shuts down the application after draining and cancelling active requests.
     */
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
        $failures = $this->drainFailures;

        try {
            ($this->hooks->shutdown)?->__invoke($this->runtimeContext, $this->shutdownReason);
        } catch (Throwable $error) {
            $failures[] = $error;
        }

        try {
            ($this->legacyShutdown)?->__invoke();
        } catch (Throwable $error) {
            $failures[] = $error;
        }

        if ($failures !== []) {
            throw new ApplicationShutdownException(null, $failures);
        }
    }

    /**
     * Runs application boot and warmup hooks once.
     */
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

        $this->booted = true;
        $this->invokeStartupHook(ApplicationStartupPhase::BOOT, $this->hooks->boot);
        $this->invokeStartupHook(ApplicationStartupPhase::WARMUP, $this->hooks->warmup);
        $this->started = true;
    }

    private function handleAdmitted(
        HttpRequest $request,
        ResponseWriterInterface $writer,
        bool $completeResponse,
    ): void {
        $context = $request->context;

        try {
            $context->activate($this->runtimeContext, $this->requestExecution);
        } catch (Throwable $error) {
            $this->admission->release($request->version);

            throw $error;
        }

        $id = spl_object_id($context);
        $this->activeContexts[$id] = $context;
        $this->runtimeContext->metrics->requestStarted($request->version);
        $finalizer = new RequestFinalizer(
            context: $context,
            version: $request->version,
            runtimeContext: $this->runtimeContext,
            requestExecution: $this->requestExecution,
            admission: $this->admission,
            memoryAtStart: memory_get_usage(true),
            reset: fn(RequestContext $requestContext): array => $this->reset($requestContext),
            finalized: function () use ($id): void {
                unset($this->activeContexts[$id]);
            },
            unhealthy: function (Throwable $failure): void {
                $this->healthFailure ??= $failure;
            },
        );

        $context->observeOwnedWorkSettled(static function () use ($finalizer): void {
            $finalizer->ownedWorkSettled();
        });
        $finalizer->attachCancellation($context->cancellation->onCancel(static function () use ($finalizer): void {
            $finalizer->cancelled();
        }));
        if ($request->body instanceof \Infocyph\Runwire\Http\Internal\StreamingRequestBody) {
            $request->body->observeCancel(static function () use ($context): void {
                $context->cancel(CancellationReason::TRANSPORT_CANCELLED);
            });
        }
        $writer->onTerminal(static function () use ($finalizer): void {
            $finalizer->terminal();
        });

        try {
            ($this->handler)($request, $writer);
            if ($completeResponse && !$writer->isEnded()) {
                $writer->end();
            }
        } catch (Throwable $error) {
            $finalizer->handlerFailed($error);
        } finally {
            $finalizer->handlerFinished();
        }
    }

    /** @param Closure(RuntimeContext): void|null $hook */
    private function invokeStartupHook(ApplicationStartupPhase $phase, ?Closure $hook): void
    {
        if ($hook === null) {
            return;
        }

        try {
            $hook($this->runtimeContext);
        } catch (Throwable $error) {
            $this->startupFailed = true;
            $this->runtimeContext->metrics->recordError(match ($phase) {
                ApplicationStartupPhase::BOOT => ApplicationErrorClass::BOOT_FAILURE,
                ApplicationStartupPhase::WARMUP => ApplicationErrorClass::WARMUP_FAILURE,
            });

            throw new ApplicationStartupException($phase, $error);
        }
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
