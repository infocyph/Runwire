<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Http1\Internal;

use Infocyph\Runwire\Http\Enum\AdaptiveLoadState;
use Infocyph\Runwire\Http\Internal\AdaptiveLoadController;
use Infocyph\Runwire\Http\Internal\AdaptiveLoadSample;

/**
 * Selects the default TCP_NODELAY policy for newly attached HTTP/1.1 connections.
 *
 * @internal
 */
final readonly class AdaptiveConnectionStrategy
{
    private AdaptiveLoadController $controller;

    /**
     * Create the worker-scoped HTTP/1.1 connection strategy.
     */
    public function __construct(?AdaptiveLoadController $controller = null)
    {
        $this->controller = $controller ?? new AdaptiveLoadController();
    }

    /**
     * Return the current worker load state.
     */
    public function state(): AdaptiveLoadState
    {
        return $this->controller->state();
    }

    /**
     * Observe worker load and choose the NODELAY default for the next HTTP/1.1 connection.
     */
    public function tcpNoDelay(AdaptiveLoadSample $sample): bool
    {
        return $this->controller->observe($sample) !== AdaptiveLoadState::THROUGHPUT;
    }
}
