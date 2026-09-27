<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Http2\Internal;

use Infocyph\Runwire\Http\Enum\AdaptiveLoadState;
use Infocyph\Runwire\Http\Internal\AdaptiveLoadController;
use Infocyph\Runwire\Http\Internal\AdaptiveLoadSample;
use InvalidArgumentException;

/**
 * Selects the bounded HTTP/2 initial-response wire budget from current load.
 *
 * @internal
 */
final readonly class AdaptiveResponseStrategy
{
    private AdaptiveLoadController $controller;

    /**
     * Create the HTTP/2 response strategy and validate its state-specific budgets.
     */
    public function __construct(
        ?AdaptiveLoadController $controller = null,
        private int $latencyWireBytes = 1_024,
        private int $balancedWireBytes = 512,
        private int $throughputWireBytes = 256,
    ) {
        if ($throughputWireBytes < 1
            || $throughputWireBytes > $balancedWireBytes
            || $balancedWireBytes > $latencyWireBytes) {
            throw new InvalidArgumentException(
                'HTTP/2 adaptive wire budgets must satisfy 0 < throughput <= balanced <= latency.',
            );
        }

        $this->controller = $controller ?? new AdaptiveLoadController(
            lowWatermarkBasisPoints: 1_000,
            highWatermarkBasisPoints: 3_000,
        );
    }

    /**
     * Return the current adaptive load state.
     */
    public function state(): AdaptiveLoadState
    {
        return $this->controller->state();
    }

    /**
     * Observe load and return the maximum one-shot wire intent for the resulting state.
     */
    public function wireLimit(AdaptiveLoadSample $sample): int
    {
        return match ($this->controller->observe($sample)) {
            AdaptiveLoadState::BALANCED => $this->balancedWireBytes,
            AdaptiveLoadState::LATENCY => $this->latencyWireBytes,
            AdaptiveLoadState::THROUGHPUT => $this->throughputWireBytes,
        };
    }
}
