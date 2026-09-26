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

    public function __construct(private readonly int $limit = 67_108_864)
    {
        if ($limit <= 0) {
            throw new InvalidArgumentException('Byte budget limit must be positive.');
        }
    }

    public function available(): int
    {
        return max(0, $this->limit - $this->used);
    }

    public function limit(): int
    {
        return $this->limit;
    }

    public function release(int $bytes): void
    {
        if ($bytes <= 0) {
            return;
        }

        $this->used = max(0, $this->used - $bytes);
    }

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

    public function used(): int
    {
        return $this->used;
    }
}
