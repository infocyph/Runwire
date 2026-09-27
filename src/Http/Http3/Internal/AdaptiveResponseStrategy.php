<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Http3\Internal;

use Infocyph\Runwire\Http\Enum\AdaptiveLoadState;
use Infocyph\Runwire\Http\Internal\AdaptiveLoadController;
use Infocyph\Runwire\Http\Internal\AdaptiveLoadSample;
use InvalidArgumentException;

/**
 * Selects the bounded HTTP/3 response write effort from outbound load.
 *
 * @internal
 */
final readonly class AdaptiveResponseStrategy
{
    private AdaptiveLoadController $controller;

    /**
     * Create the HTTP/3 outbound adaptive strategy.
     */
    public function __construct(?AdaptiveLoadController $controller = null)
    {
        $this->controller = $controller ?? new AdaptiveLoadController(
            lowWatermarkBasisPoints: 1_000,
            highWatermarkBasisPoints: 4_000,
        );
    }

    /**
     * Return the current outbound adaptive state.
     */
    public function state(): AdaptiveLoadState
    {
        return $this->controller->state();
    }

    /**
     * Observe outbound load and return a write budget capped by the configured maximum.
     */
    public function writeLimit(AdaptiveLoadSample $sample, int $maximum): int
    {
        if ($maximum < 1) {
            throw new InvalidArgumentException('HTTP/3 adaptive maximum write budget must be positive.');
        }

        return match ($this->controller->observe($sample)) {
            AdaptiveLoadState::LATENCY => self::scaled($maximum, 1, 4),
            AdaptiveLoadState::BALANCED => self::scaled($maximum, 1, 2),
            AdaptiveLoadState::THROUGHPUT => $maximum,
        };
    }

    private static function scaled(int $maximum, int $numerator, int $denominator): int
    {
        return max(1, intdiv(($maximum * $numerator) + $denominator - 1, $denominator));
    }
}
