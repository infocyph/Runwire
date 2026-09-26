<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Loop;

use Infocyph\Runwire\Metrics\DiagnosticsPolicy;

/**
 * Selects the best supported native event loop without weakening the portable fallback.
 */
final class LoopFactory
{
    /**
     * Create the native worker loop for the current runtime.
     */
    public static function native(DiagnosticsPolicy $diagnostics): LoopInterface
    {
        if (EventLoop::supported()) {
            return new EventLoop($diagnostics->callbackOverrunSeconds);
        }

        return new SelectLoop($diagnostics->callbackOverrunSeconds);
    }
}
