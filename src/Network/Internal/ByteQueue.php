<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Network\Internal;

use InvalidArgumentException;

final class ByteQueue
{
    private const int COMPACT_HEAD = 64;

    /** @var array<int, string> */
    private array $chunks = [];
    private int $head = 0;
    private int $headOffset = 0;
    private int $bytes = 0;

    public function bytes(): int { return $this->bytes; }
    public function isEmpty(): bool { return $this->bytes === 0; }

    public function append(string $bytes): void
    {
        if ($bytes === '') {
            return;
        }
        $this->chunks[] = $bytes;
        $this->bytes += strlen($bytes);
    }

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
                return;
            }
            $remaining -= $available;
            $this->bytes -= $available;
            unset($this->chunks[$this->head]);
            ++$this->head;
            $this->headOffset = 0;
        }
        $this->compact();
    }

    public function clear(): void
    {
        $this->chunks = [];
        $this->head = 0;
        $this->headOffset = 0;
        $this->bytes = 0;
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
