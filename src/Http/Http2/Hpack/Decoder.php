<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Http2\Hpack;

/**
 * Decodes bounded HPACK header blocks while maintaining dynamic-table state.
 */
final class Decoder
{
    private readonly DynamicTable $dynamic;

    private readonly HuffmanCodec $huffman;

    private int $allowedDynamicTableBytes;

    /**
     * Create an HPACK decoder with table and header-list limits.
     */
    public function __construct(
        int $maxDynamicTableBytes = 4_096,
        private readonly int $maxHeaderListBytes = 65_536,
        private readonly int $maxHeaderCount = 100,
    ) {
        if ($maxDynamicTableBytes < 0 || $maxHeaderListBytes <= 0 || $maxHeaderCount <= 0) {
            throw new \InvalidArgumentException('Invalid HPACK decoder limits.');
        }
        $this->allowedDynamicTableBytes = $maxDynamicTableBytes;
        $this->dynamic = new DynamicTable($maxDynamicTableBytes);
        $this->huffman = new HuffmanCodec();
    }

    /** @return list<array{0: string, 1: string}> */
    public function decode(string $block): array
    {
        $offset = 0;
        $headers = [];
        $headerBytes = 0;
        $sawHeader = false;
        $length = strlen($block);

        while ($offset < $length) {
            $first = ord($block[$offset]);
            if (($first & 0x80) !== 0) {
                $index = IntegerCodec::decode($block, $offset, 7);
                if ($index === 0) {
                    throw new HpackException('HPACK indexed representation cannot use index zero.');
                }
                [$name, $value] = $this->entry($index);
                $this->append($headers, $headerBytes, $name, $value);
                $sawHeader = true;

                continue;
            }

            if (($first & 0x40) !== 0) {
                $name = $this->decodeName($block, $offset, 6, $headerBytes);
                $value = $this->decodeString($block, $offset, $this->remainingHeaderBytes($headerBytes, strlen($name)));
                $this->append($headers, $headerBytes, $name, $value);
                $this->dynamic->add($name, $value);
                $sawHeader = true;

                continue;
            }

            if (($first & 0x20) !== 0) {
                if ($sawHeader) {
                    throw new HpackException('HPACK dynamic table size update must precede header representations.');
                }
                $size = IntegerCodec::decode($block, $offset, 5);
                if ($size > $this->allowedDynamicTableBytes) {
                    throw new HpackException('HPACK dynamic table size update exceeds the advertised limit.');
                }
                $this->dynamic->setMaxBytes($size);

                continue;
            }

            $name = $this->decodeName($block, $offset, 4, $headerBytes);
            $value = $this->decodeString($block, $offset, $this->remainingHeaderBytes($headerBytes, strlen($name)));
            $this->append($headers, $headerBytes, $name, $value);
            $sawHeader = true;
        }

        return $headers;
    }

    /**
     * Return the current dynamic-table byte usage.
     */
    public function dynamicTableBytes(): int
    {
        return $this->dynamic->bytes();
    }

    /**
     * Return the current dynamic-table entry count.
     */
    public function dynamicTableCount(): int
    {
        return $this->dynamic->count();
    }

    /**
     * Update the maximum dynamic-table size accepted from the peer.
     */
    public function setAllowedDynamicTableBytes(int $bytes): void
    {
        if ($bytes < 0) {
            throw new \InvalidArgumentException('HPACK dynamic table allowance cannot be negative.');
        }
        $this->allowedDynamicTableBytes = $bytes;
        if ($this->dynamic->maxBytes() > $bytes) {
            $this->dynamic->setMaxBytes($bytes);
        }
    }

    /** @param list<array{0: string, 1: string}> $headers */
    private function append(array &$headers, int &$headerBytes, string $name, string $value): void
    {
        if (count($headers) >= $this->maxHeaderCount) {
            throw new HpackException('HPACK header count exceeds configured limit.');
        }
        $size = 32 + strlen($name) + strlen($value);
        if ($size > $this->maxHeaderListBytes - $headerBytes) {
            throw new HpackException('HPACK decoded header list exceeds configured byte limit.');
        }
        $headers[] = [$name, $value];
        $headerBytes += $size;
    }

    private function decodeName(string $block, int &$offset, int $prefixBits, int $headerBytes): string
    {
        $index = IntegerCodec::decode($block, $offset, $prefixBits);
        if ($index !== 0) {
            return $this->entry($index)[0];
        }

        return $this->decodeString($block, $offset, $this->remainingHeaderBytes($headerBytes, 0));
    }

    private function decodeString(string $block, int &$offset, int $maxOutputBytes): string
    {
        if (!isset($block[$offset])) {
            throw new HpackException('Truncated HPACK string.');
        }
        $huffman = (ord($block[$offset]) & 0x80) !== 0;
        $length = IntegerCodec::decode($block, $offset, 7);
        if ($length > strlen($block) - $offset) {
            throw new HpackException('Truncated HPACK string payload.');
        }
        $encoded = substr($block, $offset, $length);
        $offset += $length;

        if (!$huffman) {
            if ($length > $maxOutputBytes) {
                throw new HpackException('HPACK string exceeds configured header-list limit.');
            }

            return $encoded;
        }

        return $this->huffman->decode($encoded, $maxOutputBytes);
    }

    /** @return array{0: string, 1: string} */
    private function entry(int $index): array
    {
        if ($index <= count(StaticTable::ENTRIES)) {
            $entry = StaticTable::get($index);
            if ($entry !== null) {
                return $entry;
            }
        } else {
            $entry = $this->dynamic->get($index - count(StaticTable::ENTRIES));
            if ($entry !== null) {
                return $entry;
            }
        }

        throw new HpackException(sprintf('Invalid HPACK table index %d.', $index));
    }

    private function remainingHeaderBytes(int $used, int $nameBytes): int
    {
        return max(0, $this->maxHeaderListBytes - $used - 32 - $nameBytes);
    }
}
