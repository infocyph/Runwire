<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Supervisor;

use Closure;

/**
 * Provides idempotent cancellation for a registered periodic worker task.
 */
final class PeriodicTaskHandle
{
    private bool $cancelled = false;

    /** @param Closure(): bool $canceller */
    public function __construct(private readonly Closure $canceller) {}

    /**
     * Cancel the periodic task once.
     */
    public function cancel(): bool
    {
        if ($this->cancelled) {
            return false;
        }

        $this->cancelled = true;

        return ($this->canceller)();
    }

    /**
     * Determine whether cancellation has already been requested.
     */
    public function cancelled(): bool
    {
        return $this->cancelled;
    }
}
