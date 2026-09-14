<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Loop;

/**
 * Exposes a point-in-time diagnostics snapshot for an event loop.
 */
interface LoopDiagnosticsProviderInterface
{
    /**
     * Capture current event-loop diagnostics.
     */
    public function diagnostics(): LoopDiagnosticsSnapshot;
}
