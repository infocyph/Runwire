<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http;

use Infocyph\Runwire\Http\Enum\AdaptivePolicyMode;
use InvalidArgumentException;

/**
 * Configures protocol-local adaptive load crossover and fixed scheduling profiles.
 */
final readonly class AdaptiveProtocolPolicy
{
    /**
     * Create and validate one protocol's adaptive scheduling policy.
     */
    public function __construct(
        public AdaptivePolicyMode $mode = AdaptivePolicyMode::AUTO,
        public int $lowWatermarkBasisPoints = 2_500,
        public int $highWatermarkBasisPoints = 6_500,
        public int $transitionSamples = 3,
        public int $ewmaNumerator = 1,
        public int $ewmaDenominator = 2,
    ) {
        if ($lowWatermarkBasisPoints < 0
            || $highWatermarkBasisPoints > 10_000
            || $lowWatermarkBasisPoints >= $highWatermarkBasisPoints) {
            throw new InvalidArgumentException(
                'Adaptive watermarks must satisfy 0 <= low < high <= 10000.',
            );
        }
        if ($transitionSamples < 1) {
            throw new InvalidArgumentException('Adaptive transition sample count must be positive.');
        }
        if ($ewmaNumerator < 1 || $ewmaDenominator < 1 || $ewmaNumerator > $ewmaDenominator) {
            throw new InvalidArgumentException('Adaptive EWMA ratio must satisfy 1 <= numerator <= denominator.');
        }
    }
}
