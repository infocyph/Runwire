<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Runtime\Internal;

use Infocyph\Runwire\Loop\LoopInterface;

/**
 * Tracks whether a native worker is stopping and coordinates loop termination.
 */
final class WorkerStopState
{
    private bool $stopping = false;

    /**
     * Creates a stop state with optional ownership of event-loop termination.
     */
    public function __construct(
        private readonly bool $stopLoopWhenDrained = true,
    ) {}

    /**
     * Reports whether worker shutdown has started.
     */
    public function isStopping(): bool
    {
        return $this->stopping;
    }

    /**
     * Marks the worker as stopping.
     */
    public function stop(): void
    {
        $this->stopping = true;
    }

    /**
     * Stops the supplied loop when this worker owns it and shutdown is active.
     */
    public function stopLoopIfStopping(LoopInterface $loop): void
    {
        if ($this->stopping && $this->stopLoopWhenDrained) {
            $loop->stop();
        }
    }
}
