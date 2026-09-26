<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Loop;

use Infocyph\Runwire\Metrics\DiagnosticsPolicy;

/**
 * Selects the best supported native event loop without weakening the portable fallback.
 */
final class LoopFactory
{
    private const int SELECT_CONNECTION_LIMIT = 256;

    /**
     * Return a stable diagnostic name for the supplied loop backend.
     */
    public static function backendName(LoopInterface $loop): string
    {
        return match (true) {
            $loop instanceof EventLoop => 'event',
            $loop instanceof SelectLoop => 'select',
            default => $loop::class,
        };
    }

    /**
     * Return the Runwire-owned native connection ceiling for the supplied loop, when one applies.
     */
    public static function connectionLimit(LoopInterface $loop): ?int
    {
        return $loop instanceof SelectLoop ? self::SELECT_CONNECTION_LIMIT : null;
    }

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
