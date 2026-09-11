<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Http2\Hpack;

final class DynamicTable
{
    private int $bytes = 0;

    /** @var list<array{0: string, 1: string, 2: int}> */
    private array $entries = [];

    public function __construct(private int $maxBytes)
    {
        if ($maxBytes < 0) {
            throw new \InvalidArgumentException('HPACK dynamic table size cannot be negative.');
        }
    }

    public function add(string $name, string $value): void
    {
        $size = 32 + strlen($name) + strlen($value);
        if ($size > $this->maxBytes) {
            $this->entries = [];
            $this->bytes = 0;

            return;
        }

        array_unshift($this->entries, [$name, $value, $size]);
        $this->bytes += $size;
        $this->evict();
    }

    public function bytes(): int
    {
        return $this->bytes;
    }

    public function count(): int
    {
        return count($this->entries);
    }

    public function exactIndex(string $name, string $value): ?int
    {
        foreach ($this->entries as $offset => $entry) {
            if ($entry[0] === $name && $entry[1] === $value) {
                return $offset + 1;
            }
        }

        return null;
    }

    /** @return array{0: string, 1: string}|null */
    public function get(int $offset): ?array
    {
        if ($offset < 1 || !isset($this->entries[$offset - 1])) {
            return null;
        }
        [$name, $value] = $this->entries[$offset - 1];

        return [$name, $value];
    }

    public function maxBytes(): int
    {
        return $this->maxBytes;
    }

    public function nameIndex(string $name): ?int
    {
        foreach ($this->entries as $offset => $entry) {
            if ($entry[0] === $name) {
                return $offset + 1;
            }
        }

        return null;
    }

    public function setMaxBytes(int $bytes): void
    {
        if ($bytes < 0) {
            throw new \InvalidArgumentException('HPACK dynamic table size cannot be negative.');
        }
        $this->maxBytes = $bytes;
        $this->evict();
    }

    private function evict(): void
    {
        while ($this->bytes > $this->maxBytes && $this->entries !== []) {
            $entry = array_pop($this->entries);
            $this->bytes -= $entry[2];
        }
    }
}
