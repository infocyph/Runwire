<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Http2\Hpack;

final class HuffmanCodec
{
    /** @var array<int, array<int, int>> */
    private array $decode = [];

    public function __construct()
    {
        foreach (HuffmanTable::CODES as $symbol => $code) {
            $length = HuffmanTable::LENGTHS[$symbol];
            $this->decode[$length][$code] = $symbol;
        }
    }

    public function encode(string $value): string
    {
        $buffer = 0;
        $bits = 0;
        $output = '';

        $length = strlen($value);
        for ($index = 0; $index < $length; ++$index) {
            $symbol = ord($value[$index]);
            $codeBits = HuffmanTable::LENGTHS[$symbol];
            $buffer = ($buffer << $codeBits) | HuffmanTable::CODES[$symbol];
            $bits += $codeBits;

            while ($bits >= 8) {
                $shift = $bits - 8;
                $output .= chr(($buffer >> $shift) & 0xFF);
                $bits -= 8;
                $buffer &= $bits === 0 ? 0 : ((1 << $bits) - 1);
            }
        }

        if ($bits > 0) {
            $padding = 8 - $bits;
            $output .= chr((($buffer << $padding) | ((1 << $padding) - 1)) & 0xFF);
        }

        return $output;
    }

    public function decode(string $encoded, int $maxOutputBytes): string
    {
        if ($maxOutputBytes < 0) {
            throw new \InvalidArgumentException('HPACK Huffman output limit cannot be negative.');
        }

        $buffer = 0;
        $bits = 0;
        $output = '';
        $length = strlen($encoded);

        for ($index = 0; $index < $length; ++$index) {
            $buffer = ($buffer << 8) | ord($encoded[$index]);
            $bits += 8;
            $this->consume($buffer, $bits, $output, $maxOutputBytes);
        }

        if ($bits > 7 || ($bits > 0 && $buffer !== (1 << $bits) - 1)) {
            throw new HpackException('Invalid HPACK Huffman padding.');
        }

        return $output;
    }

    private function consume(int &$buffer, int &$bits, string &$output, int $maxOutputBytes): void
    {
        while ($bits >= 5) {
            $matched = false;
            $maxLength = min(30, $bits);
            for ($length = 5; $length <= $maxLength; ++$length) {
                $code = ($buffer >> ($bits - $length)) & ((1 << $length) - 1);
                $symbol = $this->decode[$length][$code] ?? null;
                if ($symbol === null) {
                    continue;
                }
                if ($symbol === 256) {
                    throw new HpackException('HPACK Huffman EOS symbol is forbidden in encoded strings.');
                }
                if ($symbol < 0 || $symbol > 255) {
                    throw new HpackException('Invalid HPACK Huffman symbol.');
                }
                if (strlen($output) >= $maxOutputBytes) {
                    throw new HpackException('HPACK Huffman output exceeds configured limit.');
                }
                $output .= chr($symbol);
                $bits -= $length;
                $buffer &= $bits === 0 ? 0 : ((1 << $bits) - 1);
                $matched = true;
                break;
            }
            if ($matched) {
                continue;
            }
            if ($bits >= 30) {
                throw new HpackException('Invalid HPACK Huffman code.');
            }
            return;
        }
    }
}
