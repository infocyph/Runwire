<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Network\Internal;

use InvalidArgumentException;

/**
 * Accounts actual Runwire-owned queued bytes against a shared worker ceiling.
 */
final class ByteBudget
{
    private int $used = 0;

    /**
     * Create a byte budget with the supplied positive ceiling.
     */
    public function __construct(private readonly int $limit = 67_108_864)
    {
        if ($limit <= 0) {
            throw new InvalidArgumentException('Byte budget limit must be positive.');
        }
    }

    /**
     * Return remaining byte capacity.
     */
    public function available(): int
    {
        return max(0, $this->limit - $this->used);
    }

    /**
     * Return the configured byte ceiling.
     */
    public function limit(): int
    {
        return $this->limit;
    }

    /**
     * Release previously reserved bytes.
     */
    public function release(int $bytes): void
    {
        if ($bytes <= 0) {
            return;
        }

        $this->used = max(0, $this->used - $bytes);
    }

    /**
     * Reserve bytes when sufficient capacity remains.
     */
    public function reserve(int $bytes): bool
    {
        if ($bytes < 0) {
            throw new InvalidArgumentException('Reserved byte count cannot be negative.');
        }
        if ($bytes > $this->available()) {
            return false;
        }

        $this->used += $bytes;

        return true;
    }

    /**
     * Return the currently reserved byte count.
     */
    public function used(): int
    {
        return $this->used;
    }
}
