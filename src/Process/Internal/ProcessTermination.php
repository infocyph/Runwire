<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Process\Internal;

use Infocyph\Runwire\Exception\ProcessException;
use Infocyph\Runwire\Exception\ProcessStartException;
use Infocyph\Runwire\Internal\MonotonicTime;
use Infocyph\Runwire\Process\Command;
use Infocyph\Runwire\Process\Enum\OutputOverflowPolicy;
use Infocyph\Runwire\Process\Enum\TerminationReason;
use Infocyph\Runwire\Process\ProcessPolicy;

/**
 * Tracks and enforces the bounded child-process termination sequence.
 */
final class ProcessTermination
{
    private bool $killSent = false;

    private ?int $postKillDeadline = null;

    private TerminationReason $reason = TerminationReason::EXITED;

    private ?int $terminationDeadline = null;

    /**
     * Create termination state for one running child process.
     */
    public function __construct(
        private readonly ProcessPolicy $policy,
        private readonly int $executionDeadline,
    ) {}

    /**
     * @return array{0: ?int, 1: ?int, 2: ?int, 3: ?int}
     */
    public function deadlines(?int $postExitDeadline): array
    {
        $executionDeadline = $this->terminationDeadline === null ? $this->executionDeadline : null;
        $terminationDeadline = $this->postKillDeadline === null ? $this->terminationDeadline : null;

        return [$executionDeadline, $terminationDeadline, $this->postKillDeadline, $postExitDeadline];
    }

    /**
     * Enforce timeout and kill deadlines while the child is running.
     *
     */
    public function observe(ProcessHandle $process, Command $command, bool $running, int $now): void
    {
        if (!$running) {
            return;
        }
        if ($this->postKillDeadline !== null && $now >= $this->postKillDeadline) {
            throw new ProcessException('Child process remained running after the force-termination deadline.');
        }
        if ($this->terminationDeadline === null && $now >= $this->executionDeadline) {
            if (!$process->terminateGracefully()) {
                throw new ProcessStartException('Unable to terminate timed-out child process.');
            }
            $this->reason = TerminationReason::TIMEOUT;
            $this->terminationDeadline = MonotonicTime::addNanoseconds(
                $now,
                MonotonicTime::secondsToNanoseconds($command->terminationGraceSeconds),
            );
        }
        if (!$this->killSent && $this->terminationDeadline !== null && $now >= $this->terminationDeadline) {
            if (!self::force($process)) {
                throw new ProcessStartException('Unable to force-terminate child process.');
            }
            $this->killSent = true;
            $this->postKillDeadline = MonotonicTime::addNanoseconds(
                $now,
                MonotonicTime::secondsToNanoseconds($this->policy->postKillWaitSeconds),
            );
        }
    }

    /**
     * Begin bounded termination after output exceeds its configured ceiling.
     *
     */
    public function observeOutputLimit(ProcessHandle $process, Command $command, bool $overflowed, bool $running): void
    {
        if (!$overflowed
            || $command->overflowPolicy !== OutputOverflowPolicy::TERMINATE
            || $this->terminationDeadline !== null
            || !$running) {
            return;
        }
        if (!ProcessTerminator::graceful($process)) {
            throw new ProcessStartException('Unable to terminate child process after output-limit overflow.');
        }

        $this->reason = TerminationReason::OUTPUT_LIMIT;
        $this->terminationDeadline = MonotonicTime::addNanoseconds(
            MonotonicTime::nowNanoseconds(),
            MonotonicTime::secondsToNanoseconds($command->terminationGraceSeconds),
        );
    }

    private static function force(ProcessHandle $process): bool
    {
        $resource = $process->resource();
        $status = proc_get_status($resource);
        if (!$status['running']) {
            return true;
        }

        return $process->abort();
    }

    /**
     * Return the terminal reason selected by the termination sequence.
     */
    public function reason(): TerminationReason
    {
        return $this->reason;
    }
}
