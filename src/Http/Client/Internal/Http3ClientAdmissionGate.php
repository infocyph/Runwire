<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Client\Internal;

use InvalidArgumentException;
use OverflowException;
use RuntimeException;

/** @internal Limit one HTTP/3 session's request stream slots and queued admissions. */
final class Http3ClientAdmissionGate
{
    /** @var array<int, true> */
    private array $active = [];

    private bool $draining = false;

    /** @var array<int, true> */
    private array $pending = [];

    private bool $ready = false;

    public function __construct(
        private readonly int $maxActive = 32,
        private readonly int $maxPending = 128,
    ) {
        if ($maxActive < 1 || $maxActive > 65_536 || $maxPending < 1 || $maxPending > 65_536) {
            throw new InvalidArgumentException('HTTP/3 multiplex admission budgets must be positive and bounded.');
        }
    }

    /** Number of native request stream slots currently reserved. */
    public function activeCount(): int
    {
        return count($this->active);
    }

    /** Reject future streams and retire queued (but not active) siblings on GOAWAY. @return list<int> */
    public function drain(): array
    {
        $this->draining = true;
        $rejected = array_keys($this->pending);
        $this->pending = [];

        return $rejected;
    }

    /** Queue a unique sibling; native stream creation occurs only after start(). */
    public function enqueue(int $id): array
    {
        if ($id < 1 || isset($this->active[$id]) || isset($this->pending[$id])) {
            throw new InvalidArgumentException('HTTP/3 sibling admission ID is invalid or duplicated.');
        }
        if ($this->draining) {
            throw new RuntimeException('HTTP/3 connection is draining after peer GOAWAY.');
        }
        if (count($this->pending) >= $this->maxPending) {
            throw new OverflowException('HTTP/3 multiplex pending-stream budget exceeded.');
        }
        $this->pending[$id] = true;

        return $this->admit();
    }

    /** Number of request stream admissions waiting for available slots. */
    public function pendingCount(): int
    {
        return count($this->pending);
    }

    /** Retire one admitted or queued sibling, then grant free slots in FIFO order. @return list<int> */
    public function release(int $id): array
    {
        unset($this->active[$id], $this->pending[$id]);

        return $this->admit();
    }

    /** Mark the verified transport/control streams ready and return grantable siblings. @return list<int> */
    public function start(): array
    {
        $this->ready = true;

        return $this->admit();
    }

    /** Return the bounded set of FIFO stream admissions without bypassing a pending request. @return list<int> */
    private function admit(): array
    {
        if (!$this->ready || $this->draining) {
            return [];
        }
        $granted = [];
        while (count($this->active) < $this->maxActive && $this->pending !== []) {
            $id = array_key_first($this->pending);
            unset($this->pending[$id]);
            $this->active[$id] = true;
            $granted[] = $id;
        }

        return $granted;
    }
}
