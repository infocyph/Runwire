<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Runtime\Internal;

use Closure;

/**
 * Controls one native worker attachment without owning its event loop.
 */
final class NativeWorkerHandle
{
    private readonly Closure $closeCallback;

    private readonly Closure $drainedCallback;

    private readonly Closure $forceStopCallback;

    private readonly Closure $stopCallback;

    private bool $closed = false;

    /**
     * @param callable(): void $stop
     * @param callable(): void $forceStop
     * @param callable(): void $close
     * @param callable(): bool $drained
     */
    public function __construct(callable $stop, callable $forceStop, callable $close, callable $drained)
    {
        $this->stopCallback = Closure::fromCallable($stop);
        $this->forceStopCallback = Closure::fromCallable($forceStop);
        $this->closeCallback = Closure::fromCallable($close);
        $this->drainedCallback = Closure::fromCallable($drained);
    }

    /**
     * Release resources owned by the attachment.
     */
    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;
        ($this->closeCallback)();
    }

    /**
     * Determine whether graceful shutdown has drained this attachment.
     */
    public function drained(): bool
    {
        return $this->closed || ($this->drainedCallback)();
    }

    /**
     * Begin graceful or forced shutdown of the attachment.
     */
    public function stop(bool $force = false): void
    {
        if ($this->closed) {
            return;
        }

        ($force ? $this->forceStopCallback : $this->stopCallback)();
    }
}
