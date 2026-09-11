<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Process;

final readonly class ProcessResult
{
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

    public function outputLimitExceeded(): bool
    {
        return $this->terminationReason === TerminationReason::OUTPUT_LIMIT
            || $this->stdoutTruncated
            || $this->stderrTruncated;
    }

    public function successful(): bool
    {
        return $this->terminationReason === TerminationReason::EXITED && $this->exitCode === 0;
    }

    public function timedOut(): bool
    {
        return $this->terminationReason === TerminationReason::TIMEOUT;
    }
}
