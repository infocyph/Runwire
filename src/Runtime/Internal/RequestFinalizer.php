<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Runtime\Internal;

use Closure;
use Infocyph\Runwire\CancellationSubscription;
use Infocyph\Runwire\Exception\RequestLifecycleException;
use Infocyph\Runwire\Http\Enum\ProtocolVersion;
use Infocyph\Runwire\Metrics\Enum\ApplicationErrorClass;
use Infocyph\Runwire\RequestContext;
use Infocyph\Runwire\Runtime\Enum\CancellationReason;
use Infocyph\Runwire\Runtime\RequestExecutionPolicy;
use Infocyph\Runwire\RuntimeContext;
use Throwable;

/**
 * Owns exactly-once terminal accounting for one admitted application request.
 *
 * @internal
 */
final class RequestFinalizer
{
    /** @var Closure(): void */
    private readonly Closure $finalized;

    /** @var Closure(RequestContext): list<Throwable> */
    private readonly Closure $reset;

    /** @var Closure(Throwable): void */
    private readonly Closure $unhealthy;

    private ?CancellationSubscription $cancellationSubscription = null;

    private bool $finalizedRequest = false;

    private bool $handlerRunning = true;

    private ?Throwable $requestFailure = null;

    private bool $terminalObserved = false;

    /**
     * @param callable(RequestContext): list<Throwable> $reset
     * @param callable(): void $finalized
     * @param callable(Throwable): void $unhealthy
     */
    public function __construct(
        private readonly RequestContext $context,
        private readonly ProtocolVersion $version,
        private readonly RuntimeContext $runtimeContext,
        private readonly RequestExecutionPolicy $requestExecution,
        private readonly AdmissionController $admission,
        private readonly int $memoryAtStart,
        callable $reset,
        callable $finalized,
        callable $unhealthy,
    ) {
        $this->reset = Closure::fromCallable($reset);
        $this->finalized = Closure::fromCallable($finalized);
        $this->unhealthy = Closure::fromCallable($unhealthy);
    }

    /**
     * Attach the lifecycle-owned request cancellation subscription.
     */
    public function attachCancellation(CancellationSubscription $subscription): void
    {
        $this->cancellationSubscription = $subscription;
    }

    /**
     * Observe request cancellation after its cancellation source has been updated.
     */
    public function cancelled(): void
    {
        $this->finalizeIfReady();
    }

    /**
     * Preserve the first handler/response failure that terminates request execution.
     */
    public function handlerFailed(Throwable $failure): void
    {
        $this->requestFailure ??= $failure;
    }

    /**
     * Mark the synchronous application handler stack as exited.
     */
    public function handlerFinished(): void
    {
        $this->handlerRunning = false;
        $this->finalizeIfReady();
    }

    /**
     * Observe terminal response ownership.
     */
    public function terminal(): void
    {
        $this->terminalObserved = true;
        $this->finalizeIfReady();
    }

    /** @param list<Throwable> $resetFailures */
    private static function errorClass(
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

    private function finalize(): void
    {
        $this->finalizedRequest = true;
        $this->cancellationSubscription?->unsubscribe();
        $this->cancellationSubscription = null;
        $resetFailures = ($this->reset)($this->context);
        if ($resetFailures !== []) {
            ($this->unhealthy)($resetFailures[0]);
        }

        ($this->finalized)();
        $this->runtimeContext->metrics->requestCompleted(
            $this->context,
            $this->version,
            $this->memoryAtStart,
            self::errorClass($this->context, $this->requestFailure, $resetFailures),
        );
        $this->runtimeContext->metrics->maybeCollectGarbage($this->requestExecution->gc);
        $this->context->complete();
        $this->admission->release($this->version);

        if ($this->requestFailure !== null) {
            if ($resetFailures !== []) {
                throw new RequestLifecycleException($this->requestFailure, $resetFailures);
            }

            throw $this->requestFailure;
        }
        if ($resetFailures !== []) {
            throw new RequestLifecycleException(null, $resetFailures);
        }
    }

    private function finalizeIfReady(): void
    {
        if ($this->finalizedRequest || $this->handlerRunning) {
            return;
        }
        if (!$this->terminalObserved && $this->requestFailure === null && !$this->context->cancelled()) {
            return;
        }

        $this->finalize();
    }
}
