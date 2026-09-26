<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Network\Internal;

use InvalidArgumentException;
use OverflowException;

/**
 * Stores byte chunks with efficient front reads, discards, and compaction.
 */
final class ByteQueue
{
    private const int COMPACT_HEAD = 64;

    private int $bytes = 0;

    /** @var array<int, string> */
    private array $chunks = [];

    private int $head = 0;

    private int $headOffset = 0;

    /**
     * Create a queue optionally metered by a shared byte budget.
     */
    public function __construct(private readonly ?ByteBudget $budget = null) {}

    /**
     * Release any queued bytes still charged to the shared budget.
     */
    public function __destruct()
    {
        $this->budget?->release($this->bytes);
        $this->bytes = 0;
    }

    /**
     * Append non-empty bytes to the queue.
     */
    public function append(string $bytes): void
    {
        if ($bytes === '') {
            return;
        }
        $length = strlen($bytes);
        if ($this->budget !== null && !$this->budget->reserve($length)) {
            throw new OverflowException('Shared queued-byte budget is exhausted.');
        }
        $this->chunks[] = $bytes;
        $this->bytes += $length;
    }

    /**
     * Return remaining shared budget capacity, or PHP_INT_MAX when unmetered.
     */
    public function budgetAvailable(): int
    {
        return $this->budget?->available() ?? PHP_INT_MAX;
    }

    /**
     * Return the total queued byte count.
     */
    public function bytes(): int
    {
        return $this->bytes;
    }

    /**
     * Remove all queued bytes.
     */
    public function clear(): void
    {
        $this->budget?->release($this->bytes);
        $this->chunks = [];
        $this->head = 0;
        $this->headOffset = 0;
        $this->bytes = 0;
    }

    /**
     * Discard an exact number of bytes from the front.
     */
    public function discard(int $bytes): void
    {
        if ($bytes < 0 || $bytes > $this->bytes) {
            throw new InvalidArgumentException('Discard length exceeds queued bytes.');
        }
        $remaining = $bytes;
        while ($remaining > 0 && isset($this->chunks[$this->head])) {
            $available = strlen($this->chunks[$this->head]) - $this->headOffset;
            if ($remaining < $available) {
                $this->headOffset += $remaining;
                $this->bytes -= $remaining;
                $this->budget?->release($remaining);

                return;
            }
            $remaining -= $available;
            $this->bytes -= $available;
            $this->budget?->release($available);
            unset($this->chunks[$this->head]);
            ++$this->head;
            $this->headOffset = 0;
        }
        $this->compact();
    }

    /**
     * Return up to the requested bytes from the front without consuming them.
     */
    public function front(int $maxBytes): string
    {
        if ($maxBytes <= 0 || $this->bytes === 0 || !isset($this->chunks[$this->head])) {
            return '';
        }
        $chunk = $this->chunks[$this->head];
        $available = strlen($chunk) - $this->headOffset;
        $length = min($maxBytes, $available);

        return $this->headOffset === 0 && $length === $available
            ? $chunk
            : substr($chunk, $this->headOffset, $length);
    }

    /**
     * Determine whether the queue contains no bytes.
     */
    public function isEmpty(): bool
    {
        return $this->bytes === 0;
    }

    /**
     * Return up to the requested queued bytes without consuming them.
     */
    public function peek(int $maxBytes = PHP_INT_MAX): string
    {
        if ($maxBytes < 0) {
            throw new InvalidArgumentException('Maximum peek bytes cannot be negative.');
        }
        if ($maxBytes === 0 || $this->bytes === 0) {
            return '';
        }

        $remaining = min($maxBytes, $this->bytes);
        $parts = [];
        $index = $this->head;
        $offset = $this->headOffset;
        while ($remaining > 0 && isset($this->chunks[$index])) {
            $chunk = $this->chunks[$index];
            $available = strlen($chunk) - $offset;
            $take = min($remaining, $available);
            $parts[] = $offset === 0 && $take === $available
                ? $chunk
                : substr($chunk, $offset, $take);
            $remaining -= $take;
            ++$index;
            $offset = 0;
        }

        return count($parts) === 1 ? $parts[0] : implode('', $parts);
    }

    /**
     * Consume and return up to the requested number of bytes.
     */
    public function read(int $maxBytes): string
    {
        if ($maxBytes < 0) {
            throw new InvalidArgumentException('Maximum read bytes cannot be negative.');
        }
        if ($maxBytes === 0 || $this->bytes === 0) {
            return '';
        }

        $remaining = min($maxBytes, $this->bytes);
        $parts = [];
        while ($remaining > 0 && isset($this->chunks[$this->head])) {
            $chunk = $this->chunks[$this->head];
            $available = strlen($chunk) - $this->headOffset;
            $take = min($remaining, $available);
            $parts[] = $this->headOffset === 0 && $take === $available
                ? $chunk
                : substr($chunk, $this->headOffset, $take);
            $this->discard($take);
            $remaining -= $take;
        }

        return count($parts) === 1 ? $parts[0] : implode('', $parts);
    }

    private function compact(): void
    {
        if ($this->head < self::COMPACT_HEAD || $this->head * 2 < $this->head + count($this->chunks)) {
            return;
        }
        $this->chunks = array_values($this->chunks);
        $this->head = 0;
    }
}
