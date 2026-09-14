<?php

declare(strict_types=1);

namespace Infocyph\Runwire;

use Infocyph\Runwire\Internal\MonotonicTime;
use InvalidArgumentException;

/**
 * Represents an optional monotonic deadline for request execution.
 */
final readonly class RequestDeadline
{
    /**
     * Creates a request deadline from an absolute monotonic timestamp.
     */
    public function __construct(public ?int $monotonicNanoseconds)
    {
        if ($monotonicNanoseconds !== null && $monotonicNanoseconds < 0) {
            throw new InvalidArgumentException('Request deadline must be null or a non-negative monotonic timestamp.');
        }
    }

    /**
     * Creates a deadline a fixed duration after a monotonic start time.
     */
    public static function afterSeconds(float $seconds, int $startNanoseconds): self
    {
        if (!is_finite($seconds) || $seconds <= 0) {
            throw new InvalidArgumentException('Request deadline duration must be finite and positive.');
        }
        if ($startNanoseconds < 0) {
            throw new InvalidArgumentException('Request deadline start time must be non-negative.');
        }

        return new self(MonotonicTime::deadlineAfterSeconds($startNanoseconds, $seconds));
    }

    /**
     * Creates a deadline that never expires.
     */
    public static function unlimited(): self
    {
        return new self(null);
    }

    /**
     * Reports whether the deadline has expired at the supplied or current monotonic time.
     */
    public function expired(?int $nowNanoseconds = null): bool
    {
        if ($this->monotonicNanoseconds === null) {
            return false;
        }

        return ($nowNanoseconds ?? MonotonicTime::nowNanoseconds()) >= $this->monotonicNanoseconds;
    }

    /**
     * Returns remaining seconds, or null when the deadline is unlimited.
     */
    public function remainingSeconds(?int $nowNanoseconds = null): ?float
    {
        if ($this->monotonicNanoseconds === null) {
            return null;
        }

        $remaining = $this->monotonicNanoseconds - ($nowNanoseconds ?? MonotonicTime::nowNanoseconds());

        return max(0, $remaining) / MonotonicTime::NANOSECONDS_PER_SECOND;
    }
}
