<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Protocol\Enum;

/**
 * Defines supported fixed-width frame length-prefix encodings.
 */
enum LengthPrefixFormat: int
{
    case UINT16_BE = 2;

    case UINT32_BE = 4;

    case UINT8 = 1;

    /**
     * Returns the largest frame length representable by this prefix format.
     */
    public function maximum(): int
    {
        return match ($this) {
            self::UINT8 => 0xFF,
            self::UINT16_BE => 0xFFFF,
            self::UINT32_BE => 0xFFFF_FFFF,
        };
    }
}
