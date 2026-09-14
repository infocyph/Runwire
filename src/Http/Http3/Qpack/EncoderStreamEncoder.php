<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Http3\Qpack;

/**
 * Encodes local QPACK encoder-stream instructions.
 */
final class EncoderStreamEncoder
{
    /**
     * Encode a Duplicate instruction.
     */
    public function duplicate(int $relativeIndex): string
    {
        return IntegerCodec::encode($relativeIndex, 5, 0x00);
    }

    /**
     * Encode an Insert With Literal Name instruction.
     */
    public function insertLiteralName(string $name, string $value): string
    {
        return StringCodec::encode($name, 5, 0x40, 0x20)
            . StringCodec::encode($value, 7, 0x00, 0x80);
    }

    /**
     * Encode an Insert With Name Reference instruction.
     */
    public function insertNameReference(int $index, bool $static, string $value): string
    {
        return IntegerCodec::encode($index, 6, 0x80 | ($static ? 0x40 : 0x00))
            . StringCodec::encode($value, 7, 0x00, 0x80);
    }

    /**
     * Encode a Set Dynamic Table Capacity instruction.
     */
    public function setCapacity(int $capacity): string
    {
        return IntegerCodec::encode($capacity, 5, 0x20);
    }
}
