<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Client;

use Infocyph\Runwire\CancellationSource;
use Infocyph\Runwire\Runtime\Enum\CancellationReason;

/** Owns cancellation until one HTTP exchange terminates. */
final class OutboundHttpHandle
{
    private bool $settled = false;

    public function __construct(private readonly CancellationSource $source) {}

    public function cancel(): bool
    {
        return !$this->settled && $this->source->cancel(CancellationReason::HOST_CANCELLED);
    }

    public function complete(): void
    {
        if ($this->settled) {
            return;
        }
        $this->settled = true;
        $this->source->dispose();
    }

    public function settled(): bool
    {
        return $this->settled;
    }
}
