<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Http3\Qpack;

use Infocyph\Runwire\Http\Http3\Enum\ErrorCode;
use Infocyph\Runwire\Http\Http3\Http3Exception;

/**
 * Maintains the bounded QPACK dynamic table and reference accounting.
 */
final class DynamicTable
{
    private int $capacity = 0;

    /** @var array<int, array{name: string, value: string, size: int, pins: int}> */
    private array $entries = [];

    private int $insertCount = 0;

    private int $size = 0;

    /**
     * Create a dynamic table with the peer-advertised maximum capacity.
     */
    public function __construct(private readonly int $maxCapacity)
    {
        if ($maxCapacity < 0) {
            throw new \InvalidArgumentException('QPACK maximum dynamic table capacity cannot be negative.');
        }
    }

    /**
     * Return the currently configured dynamic-table capacity.
     */
    public function capacity(): int
    {
        return $this->capacity;
    }

    /** @return array{name: string, value: string, size: int, pins: int} */
    public function entry(int $absoluteIndex, ErrorCode $errorCode = ErrorCode::QPACK_DECOMPRESSION_FAILED): array
    {
        $entry = $this->entries[$absoluteIndex] ?? null;
        if ($entry === null) {
            throw new Http3Exception(
                $errorCode,
                sprintf('QPACK dynamic table entry %d is unavailable.', $absoluteIndex),
            );
        }

        return $entry;
    }

    /**
     * Find the newest dynamic-table entry matching both name and value.
     */
    public function findExact(string $name, string $value): ?int
    {
        $indexes = array_keys($this->entries);

        for ($offset = count($indexes) - 1; $offset >= 0; --$offset) {
            $index = $indexes[$offset];
            $entry = $this->entries[$index];
            if ($entry['name'] === $name && $entry['value'] === $value) {
                return $index;
            }
        }

        return null;
    }

    /**
     * Find the newest dynamic-table entry matching a field name.
     */
    public function findName(string $name): ?int
    {
        $indexes = array_keys($this->entries);

        for ($offset = count($indexes) - 1; $offset >= 0; --$offset) {
            $index = $indexes[$offset];
            if ($this->entries[$index]['name'] === $name) {
                return $index;
            }
        }

        return null;
    }

    /**
     * Return the total number of inserted entries.
     */
    public function insertCount(): int
    {
        return $this->insertCount;
    }

    /**
     * Insert a locally generated entry when eviction constraints permit it.
     */
    public function insertLocal(string $name, string $value): ?int
    {
        return $this->insert($name, $value, false);
    }

    /**
     * Insert a peer-requested entry or fail the QPACK encoder stream.
     */
    public function insertPeer(string $name, string $value): int
    {
        $index = $this->insert($name, $value, true);
        if ($index === null) {
            throw new Http3Exception(
                ErrorCode::QPACK_ENCODER_STREAM_ERROR,
                'Peer QPACK insertion cannot fit within the configured dynamic table capacity.',
            );
        }

        return $index;
    }

    /**
     * Return the immutable maximum dynamic-table capacity.
     */
    public function maxCapacity(): int
    {
        return $this->maxCapacity;
    }

    /**
     * Return the maximum number of QPACK entries implied by the maximum capacity.
     */
    public function maxEntries(): int
    {
        return intdiv($this->maxCapacity, 32);
    }

    /**
     * Pin a dynamic entry while an outstanding field section references it.
     */
    public function pin(int $absoluteIndex): void
    {
        $entry = $this->entry($absoluteIndex, ErrorCode::QPACK_DECODER_STREAM_ERROR);
        ++$entry['pins'];
        $this->entries[$absoluteIndex] = $entry;
    }

    /**
     * Resolve a QPACK post-base index to an absolute dynamic-table index.
     */
    public function postBaseIndex(int $base, int $postBaseIndex): int
    {
        return CheckedInteger::add(
            $base,
            $postBaseIndex,
            ErrorCode::QPACK_DECOMPRESSION_FAILED,
            'QPACK post-base index exceeds the platform integer range.',
        );
    }

    /**
     * Resolve a QPACK pre-base relative index to an absolute dynamic-table index.
     */
    public function preBaseIndex(int $base, int $relativeIndex): int
    {
        $absolute = $base - $relativeIndex - 1;
        if ($absolute < 0) {
            throw new Http3Exception(
                ErrorCode::QPACK_DECOMPRESSION_FAILED,
                'QPACK relative index precedes the dynamic table.',
            );
        }

        return $absolute;
    }

    /**
     * Resolve an encoder-stream relative index against the current insert count.
     */
    public function relativeToInsertCount(
        int $relativeIndex,
        ErrorCode $errorCode = ErrorCode::QPACK_ENCODER_STREAM_ERROR,
    ): int {
        $absolute = $this->insertCount - $relativeIndex - 1;
        if ($absolute < 0 || !isset($this->entries[$absolute])) {
            throw new Http3Exception($errorCode, 'Invalid QPACK relative dynamic table index.');
        }

        return $absolute;
    }

    /**
     * Set local dynamic-table capacity when pinned references allow eviction.
     */
    public function setCapacityLocal(int $capacity): bool
    {
        return $this->setCapacity($capacity, false);
    }

    /**
     * Apply peer-requested dynamic-table capacity.
     */
    public function setCapacityPeer(int $capacity): void
    {
        if (!$this->setCapacity($capacity, true)) {
            throw new Http3Exception(
                ErrorCode::QPACK_ENCODER_STREAM_ERROR,
                'Invalid peer QPACK dynamic table capacity.',
            );
        }
    }

    /**
     * Return the current dynamic-table byte size.
     */
    public function size(): int
    {
        return $this->size;
    }

    /**
     * Release one outstanding reference to a dynamic entry.
     */
    public function unpin(int $absoluteIndex): void
    {
        $entry = $this->entries[$absoluteIndex] ?? null;
        if ($entry === null || $entry['pins'] === 0) {
            throw new Http3Exception(
                ErrorCode::QPACK_DECODER_STREAM_ERROR,
                'QPACK dynamic table reference accounting underflow.',
            );
        }

        --$entry['pins'];
        $this->entries[$absoluteIndex] = $entry;
    }

    private static function entrySize(string $name, string $value): int
    {
        return 32 + strlen($name) + strlen($value);
    }

    private function insert(string $name, string $value, bool $ignorePins): ?int
    {
        $entrySize = self::entrySize($name, $value);
        if ($entrySize > $this->capacity || !$this->makeRoom($entrySize, $ignorePins)) {
            return null;
        }
        if ($this->insertCount === PHP_INT_MAX) {
            throw new Http3Exception(
                $ignorePins ? ErrorCode::QPACK_ENCODER_STREAM_ERROR : ErrorCode::INTERNAL_ERROR,
                'QPACK dynamic table insert count exhausted the platform integer range.',
            );
        }

        $index = $this->insertCount++;
        $this->entries[$index] = [
            'name' => $name,
            'value' => $value,
            'size' => $entrySize,
            'pins' => 0,
        ];
        $this->size += $entrySize;

        return $index;
    }

    private function makeRoom(int $neededBytes, bool $ignorePins): bool
    {
        foreach (array_keys($this->entries) as $index) {
            if ($this->size + $neededBytes <= $this->capacity) {
                return true;
            }

            $entry = $this->entries[$index];
            if (!$ignorePins && $entry['pins'] > 0) {
                return false;
            }

            $this->size -= $entry['size'];
            unset($this->entries[$index]);
        }

        return $this->size + $neededBytes <= $this->capacity;
    }

    private function setCapacity(int $capacity, bool $ignorePins): bool
    {
        if ($capacity < 0 || $capacity > $this->maxCapacity) {
            return false;
        }
        if ($capacity >= $this->size) {
            $this->capacity = $capacity;

            return true;
        }

        foreach (array_keys($this->entries) as $index) {
            if ($this->size <= $capacity) {
                break;
            }

            $entry = $this->entries[$index];
            if (!$ignorePins && $entry['pins'] > 0) {
                return false;
            }

            $this->size -= $entry['size'];
            unset($this->entries[$index]);
        }

        if ($this->size > $capacity) {
            return false;
        }

        $this->capacity = $capacity;

        return true;
    }
}
