<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Process;

use Infocyph\Runwire\Process\Enum\TerminationReason;

/**
 * Captures child-process exit state, output, and execution statistics.
 */
final readonly class ProcessResult
{
    /**
     * Creates an immutable process result snapshot.
     */
    public function __construct(
        public ?int $exitCode,
        public string $stdout,
        public string $stderr,
        public int $stdoutBytes,
        public int $stderrBytes,
        public bool $stdoutTruncated,
        public bool $stderrTruncated,
        public TerminationReason $terminationReason,
        public ?int $terminationSignal,
        public float $durationSeconds,
    ) {}

    /**
     * Reports whether the configured output limit was reached.
     */
    public function outputLimitExceeded(): bool
    {
        return $this->terminationReason === TerminationReason::OUTPUT_LIMIT
            || $this->stdoutTruncated
            || $this->stderrTruncated;
    }

    /**
     * Reports whether the process exited normally with status zero.
     */
    public function successful(): bool
    {
        return $this->terminationReason === TerminationReason::EXITED && $this->exitCode === 0;
    }

    /**
     * Reports whether execution ended because its timeout elapsed.
     */
    public function timedOut(): bool
    {
        return $this->terminationReason === TerminationReason::TIMEOUT;
    }
}
