<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Internal;

use InvalidArgumentException;

/**
 * Normalized protocol load signals consumed by the adaptive controller.
 *
 * @internal
 */
final readonly class AdaptiveLoadSample
{
    public const int MAX_BASIS_POINTS = 10_000;

    public function __construct(
        public bool $pressured,
        public int $backlogBasisPoints,
        public int $activeBasisPoints,
    ) {
        self::assertBasisPoints($backlogBasisPoints, 'backlogBasisPoints');
        self::assertBasisPoints($activeBasisPoints, 'activeBasisPoints');
    }

    public static function fromCounters(
        bool $pressured,
        int $queuedBytes,
        int $queueCapacityBytes,
        int $activeWork,
        int $activeCapacity,
    ): self {
        if ($queuedBytes < 0 || $activeWork < 0) {
            throw new InvalidArgumentException('Adaptive load counters cannot be negative.');
        }
        if ($queueCapacityBytes <= 0 || $activeCapacity <= 0) {
            throw new InvalidArgumentException('Adaptive load capacities must be positive.');
        }

        return new self(
            $pressured,
            self::ratio($queuedBytes, $queueCapacityBytes),
            self::ratio($activeWork, $activeCapacity),
        );
    }

    /**
     * Return a cheap normalized score where backlog has higher weight than active-count pressure.
     */
    public function score(): int
    {
        return intdiv(($this->backlogBasisPoints * 7) + ($this->activeBasisPoints * 3), 10);
    }

    private static function assertBasisPoints(int $value, string $name): void
    {
        if ($value < 0 || $value > self::MAX_BASIS_POINTS) {
            throw new InvalidArgumentException(sprintf('%s must be between 0 and 10000.', $name));
        }
    }

    private static function ratio(int $value, int $capacity): int
    {
        if ($value >= $capacity) {
            return self::MAX_BASIS_POINTS;
        }

        return intdiv($value * self::MAX_BASIS_POINTS, $capacity);
    }
}
