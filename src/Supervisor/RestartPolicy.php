<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Supervisor;

use InvalidArgumentException;

/**
 * Defines worker restart budgets and exponential backoff behavior.
 */
final readonly class RestartPolicy
{
    /**
     * Create a worker restart policy.
     */
    public function __construct(
        public int $maxRestarts = 10,
        public float $windowSeconds = 60.0,
        public float $initialBackoffSeconds = 0.05,
        public float $maxBackoffSeconds = 5.0,
    ) {
        if ($maxRestarts < 0) {
            throw new InvalidArgumentException('Restart budget cannot be negative.');
        }

        if (!is_finite($windowSeconds) || $windowSeconds <= 0) {
            throw new InvalidArgumentException('Restart window must be finite and positive.');
        }

        if (!is_finite($initialBackoffSeconds) || $initialBackoffSeconds < 0) {
            throw new InvalidArgumentException('Initial restart backoff must be finite and non-negative.');
        }

        if (!is_finite($maxBackoffSeconds) || $maxBackoffSeconds < $initialBackoffSeconds) {
            throw new InvalidArgumentException('Maximum restart backoff must be finite and at least the initial backoff.');
        }
    }

    /**
     * Return the bounded exponential backoff delay for a restart attempt.
     */
    public function backoffForAttempt(int $attempt): float
    {
        if ($attempt <= 0 || $this->initialBackoffSeconds === 0.0) {
            return 0.0;
        }

        $exponent = min($attempt - 1, 30);

        return min(
            $this->maxBackoffSeconds,
            $this->initialBackoffSeconds * (2 ** $exponent),
        );
    }
}
