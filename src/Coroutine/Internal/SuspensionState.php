<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Coroutine\Internal;

/** @internal */
final class SuspensionState
{
    private ?CancellationWait $cancellation = null;

    private ?int $handle = null;

    private bool $settled = false;

    public function beginSettlement(): bool
    {
        if ($this->settled) {
            return false;
        }

        $this->settled = true;

        return true;
    }

    public function closeCancellation(): void
    {
        $this->cancellation?->close();
        $this->cancellation = null;
    }

    public function handle(): ?int
    {
        return $this->handle;
    }

    public function isSettled(): bool
    {
        return $this->settled;
    }

    public function setCancellation(CancellationWait $cancellation): void
    {
        $this->cancellation = $cancellation;
    }

    public function setHandle(?int $handle): void
    {
        $this->handle = $handle;
    }

    public function takeHandle(): ?int
    {
        $handle = $this->handle;
        $this->handle = null;

        return $handle;
    }
}
