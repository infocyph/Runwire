<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Coroutine\Internal;

/** @internal */
final class SuspensionState
{
    private ?CancellationWait $cancellation = null;

    private ?int $handle = null;

    private bool $settled = false;

    /**
     * Atomically begin settling this suspension.
     */
    public function beginSettlement(): bool
    {
        if ($this->settled) {
            return false;
        }

        $this->settled = true;

        return true;
    }

    /**
     * Close and release the attached cancellation observer.
     */
    public function closeCancellation(): void
    {
        $this->cancellation?->close();
        $this->cancellation = null;
    }

    /**
     * Return the current loop or waiter handle.
     */
    public function handle(): ?int
    {
        return $this->handle;
    }

    /**
     * Determine whether the suspension has settled.
     */
    public function isSettled(): bool
    {
        return $this->settled;
    }

    /**
     * Attach the cancellation observer for this suspension.
     */
    public function setCancellation(CancellationWait $cancellation): void
    {
        $this->cancellation = $cancellation;
    }

    /**
     * Store the active loop or waiter handle.
     */
    public function setHandle(?int $handle): void
    {
        $this->handle = $handle;
    }

    /**
     * Remove and return the active handle.
     */
    public function takeHandle(): ?int
    {
        $handle = $this->handle;
        $this->handle = null;

        return $handle;
    }
}
