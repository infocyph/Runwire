<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Internal;

use InvalidArgumentException;
use OverflowException;

/**
 * Provides checked monotonic-time conversion and deadline arithmetic.
 *
 * @internal
 */
final class MonotonicTime
{
    public const int NANOSECONDS_PER_SECOND = 1_000_000_000;

    /**
     * Add a non-negative nanosecond delta without overflowing the platform integer range.
     */
    public static function addNanoseconds(int $base, int $delta): int
    {
        if ($base < 0 || $delta < 0) {
            throw new InvalidArgumentException('Monotonic nanosecond values must be non-negative.');
        }
        if ($delta > PHP_INT_MAX - $base) {
            throw new OverflowException('Monotonic deadline exceeds the platform integer range.');
        }

        return $base + $delta;
    }

    /**
     * Build a checked absolute deadline from a monotonic start and duration.
     */
    public static function deadlineAfterSeconds(int $startNanoseconds, float $seconds): int
    {
        return self::addNanoseconds(
            $startNanoseconds,
            self::secondsToNanoseconds($seconds, true),
        );
    }

    /**
     * Return the current monotonic clock value in nanoseconds.
     */
    public static function nowNanoseconds(): int
    {
        $now = hrtime(true);

        return is_int($now) ? $now : (int) $now;
    }

    /**
     * Convert a finite non-negative duration to nanoseconds with checked arithmetic.
     */
    public static function secondsToNanoseconds(float $seconds, bool $roundUp = false): int
    {
        if (!is_finite($seconds) || $seconds < 0.0) {
            throw new InvalidArgumentException('Duration must be finite and non-negative.');
        }

        $maxWholeSeconds = intdiv(PHP_INT_MAX, self::NANOSECONDS_PER_SECOND);
        if ($seconds >= $maxWholeSeconds + 1.0) {
            throw new OverflowException('Duration exceeds the platform integer nanosecond range.');
        }

        $wholeSeconds = (int) floor($seconds);
        $wholeNanoseconds = $wholeSeconds * self::NANOSECONDS_PER_SECOND;
        $fraction = $seconds - $wholeSeconds;
        $fractionNanoseconds = $roundUp
            ? (int) ceil($fraction * self::NANOSECONDS_PER_SECOND)
            : (int) round($fraction * self::NANOSECONDS_PER_SECOND);

        return self::addNanoseconds($wholeNanoseconds, $fractionNanoseconds);
    }
}
