<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Http2\Hpack;

final class Encoder
{
    private readonly DynamicTable $dynamic;
    private readonly HuffmanCodec $huffman;
    private ?int $pendingTableSize = null;

    /** @var array<string, true> */
    private const array NEVER_INDEX = [
        'authorization' => true,
        'cookie' => true,
        'set-cookie' => true,
        'proxy-authorization' => true,
    ];

    public function __construct(int $maxDynamicTableBytes = 4_096)
    {
        if ($maxDynamicTableBytes < 0) {
            throw new \InvalidArgumentException('HPACK encoder table size cannot be negative.');
        }
        $this->dynamic = new DynamicTable($maxDynamicTableBytes);
        $this->huffman = new HuffmanCodec();
    }

    public function setPeerMaxDynamicTableBytes(int $bytes): void
    {
        if ($bytes < 0) {
            throw new \InvalidArgumentException('HPACK peer table size cannot be negative.');
        }
        if ($bytes === $this->dynamic->maxBytes()) {
            return;
        }
        $this->dynamic->setMaxBytes($bytes);
        $this->pendingTableSize = $bytes;
    }

    /**
     * @param list<array{0: string, 1: string}> $headers
     */
    public function encode(array $headers, bool $useHuffman = true): string
    {
        $encoded = '';
        if ($this->pendingTableSize !== null) {
            $encoded .= IntegerCodec::encode($this->pendingTableSize, 5, 0x20);
            $this->pendingTableSize = null;
        }

        foreach ($headers as $header) {
            if (!isset($header[0], $header[1]) || !is_string($header[0]) || !is_string($header[1])) {
                throw new \InvalidArgumentException('HPACK encoder headers must be [name, value] string pairs.');
            }
            $name = strtolower($header[0]);
            $value = $header[1];
            $exact = $this->exactIndex($name, $value);
            if ($exact !== null) {
                $encoded .= IntegerCodec::encode($exact, 7, 0x80);
                continue;
            }

            $neverIndex = isset(self::NEVER_INDEX[$name]);
            $nameIndex = $this->nameIndex($name);
            $prefix = $neverIndex ? 0x10 : 0x40;
            $prefixBits = $neverIndex ? 4 : 6;
            $encoded .= IntegerCodec::encode($nameIndex ?? 0, $prefixBits, $prefix);
            if ($nameIndex === null) {
                $encoded .= $this->encodeString($name, $useHuffman);
            }
            $encoded .= $this->encodeString($value, $useHuffman);
            if (!$neverIndex) {
                $this->dynamic->add($name, $value);
            }
        }

        return $encoded;
    }

    public function dynamicTableBytes(): int
    {
        return $this->dynamic->bytes();
    }

    private function encodeString(string $value, bool $useHuffman): string
    {
        if (!$useHuffman || $value === '') {
            return IntegerCodec::encode(strlen($value), 7) . $value;
        }
        $huffman = $this->huffman->encode($value);
        if (strlen($huffman) >= strlen($value)) {
            return IntegerCodec::encode(strlen($value), 7) . $value;
        }

        return IntegerCodec::encode(strlen($huffman), 7, 0x80) . $huffman;
    }

    private function exactIndex(string $name, string $value): ?int
    {
        foreach (StaticTable::ENTRIES as $offset => $entry) {
            if ($entry[0] === $name && $entry[1] === $value) {
                return $offset + 1;
            }
        }
        $dynamic = $this->dynamic->exactIndex($name, $value);

        return $dynamic === null ? null : count(StaticTable::ENTRIES) + $dynamic;
    }

    private function nameIndex(string $name): ?int
    {
        foreach (StaticTable::ENTRIES as $offset => $entry) {
            if ($entry[0] === $name) {
                return $offset + 1;
            }
        }
        $dynamic = $this->dynamic->nameIndex($name);

        return $dynamic === null ? null : count(StaticTable::ENTRIES) + $dynamic;
    }
}
