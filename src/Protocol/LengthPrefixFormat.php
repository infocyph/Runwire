<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Protocol;

enum LengthPrefixFormat: int
{
    case UINT8 = 1;
    case UINT16_BE = 2;
    case UINT32_BE = 4;

    public function maximum(): int
    {
        return match ($this) {
            self::UINT8 => 0xFF,
            self::UINT16_BE => 0xFFFF,
            self::UINT32_BE => 0xFFFF_FFFF,
        };
    }
}
