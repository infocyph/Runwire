<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Client;

use Infocyph\Runwire\CancellationSource;
use Infocyph\Runwire\Runtime\Enum\CancellationReason;

/** Owns cancellation until one HTTP exchange terminates. */
final class OutboundHttpHandle
{
    private bool $settled = false;

    /** Create an owned outbound cancellation handle. */
    public function __construct(private readonly CancellationSource $source) {}

    /** Cancel the owned exchange once. */
    public function cancel(): bool
    {
        return !$this->settled && $this->source->cancel(CancellationReason::HOST_CANCELLED);
    }

    /** Dispose cancellation links on terminal completion. */
    public function complete(): void
    {
        if ($this->settled) {
            return;
        }
        $this->settled = true;
        $this->source->dispose();
    }

    /** Report whether the exchange reached a terminal state. */
    public function settled(): bool
    {
        return $this->settled;
    }
}
