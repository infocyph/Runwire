<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Runtime\Internal;

use Closure;
use Infocyph\Runwire\Http\Http3\Quic\PhpQuicHttp3Worker;
use Infocyph\Runwire\Loop\LoopInterface;
use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Runtime\RuntimeApplicationInterface;
use Infocyph\Runwire\Supervisor\WorkerContext;
use Throwable;

/**
 * Owns one portable HTTP/3 attachment lifecycle on a shared native loop.
 *
 * @internal
 */
final class NativeHttp3Attachment
{
    private readonly WorkerStopState $state;

    private ?int $drainTimer = null;

    private ?int $pollTimer = null;

    private ?int $stopWatcher = null;

    /**
     * Creates a portable HTTP/3 attachment controller.
     *
     * @param Closure(): void $observeTransport
     */
    public function __construct(
        private readonly LoopInterface $loop,
        private readonly SelectLoop $taskLoop,
        private readonly WorkerContext $context,
        private readonly RuntimeApplicationInterface $application,
        private readonly PhpQuicHttp3Worker $worker,
        private readonly WorkerDiagnosticsSampler $sampler,
        private readonly Closure $observeTransport,
    ) {
        $this->state = new WorkerStopState(false);
    }

    /**
     * Starts polling and returns the lifecycle handle for this attachment.
     */
    public function start(float $pollInterval): NativeWorkerHandle
    {
        try {
            $this->application->start();
            $this->observe();
            $this->sampler->sample(true);
            $this->pollTimer = $this->loop->repeat($pollInterval, $this->poll(...));
            $this->stopWatcher = $this->loop->onReadable($this->context->stopStream(), $this->beginDrain(...));
            $this->context->ready();
        } catch (Throwable $error) {
            $this->worker->stopAccepting();
            $this->worker->forceClose();
            $this->application->shutdown($this->context->shutdownReason());

            throw $error;
        }

        return new NativeWorkerHandle(
            stop: $this->requestStop(...),
            forceStop: $this->forceStop(...),
            close: $this->close(...),
            drained: $this->drained(...),
        );
    }

    private function beginDrain(): void
    {
        $this->context->consumeStopWake();
        if ($this->state->isStopping()) {
            return;
        }

        $this->state->stop();
        $this->application->drain($this->context->shutdownReason());
        $this->worker->stopAccepting();
        $this->drainTimer = $this->loop->delay(
            $this->context->recyclePolicy->gracefulTimeoutSeconds,
            $this->expireDrain(...),
        );
        $this->sampler->sample(true);
        $this->finishDrain();
    }

    private function cancelDrainTimer(): void
    {
        if ($this->drainTimer === null) {
            return;
        }

        $this->loop->cancel($this->drainTimer);
        $this->drainTimer = null;
    }

    private function cancelPollTimer(): void
    {
        if ($this->pollTimer === null) {
            return;
        }

        $this->loop->cancel($this->pollTimer);
        $this->pollTimer = null;
    }

    private function cancelStopWatcher(): void
    {
        if ($this->stopWatcher === null) {
            return;
        }

        $this->loop->cancel($this->stopWatcher);
        $this->stopWatcher = null;
    }

    private function close(): void
    {
        $this->cancelStopWatcher();
        $this->cancelPollTimer();
        $this->cancelDrainTimer();
        $this->worker->stopAccepting();
        if (!$this->worker->drainComplete()) {
            $this->worker->forceClose();
        }
        $this->application->shutdown($this->context->shutdownReason());
        $this->observe();
        $this->sampler->sample(true);
    }

    private function drained(): bool
    {
        return $this->state->isStopping() && $this->worker->drainComplete();
    }

    private function expireDrain(): void
    {
        if (!$this->worker->drainComplete()) {
            $this->worker->forceClose();
        }
        $this->finishDrain();
    }

    private function finishDrain(): void
    {
        if (!$this->drained()) {
            return;
        }

        $this->cancelPollTimer();
        $this->cancelDrainTimer();
    }

    private function forceStop(): void
    {
        $this->context->requestStop();
        $this->beginDrain();
        if (!$this->worker->drainComplete()) {
            $this->worker->forceClose();
        }
        $this->finishDrain();
        $this->sampler->sample(true);
    }

    private function observe(): void
    {
        ($this->observeTransport)();
    }

    private function poll(): void
    {
        $this->worker->tick(0.0);
        $this->taskLoop->tick();
        $this->observe();
        $this->sampler->sample();
        $this->finishDrain();
    }

    private function requestStop(): void
    {
        $this->context->requestStop();
    }
}
