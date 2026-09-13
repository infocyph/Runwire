<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Supervisor;

use Closure;

final class PeriodicTaskHandle
{
    /** @var Closure(): bool */
    private readonly Closure $canceller;

    private bool $cancelled = false;

    /** @param Closure(): bool $canceller */
    public function __construct(Closure $canceller)
    {
        $this->canceller = $canceller;
    }

    public function cancel(): bool
    {
        if ($this->cancelled) {
            return false;
        }

        $this->cancelled = true;

        return ($this->canceller)();
    }

    public function cancelled(): bool
    {
        return $this->cancelled;
    }
}
