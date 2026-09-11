<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Http3\Qpack;

use Infocyph\Runwire\Http\Http3\ErrorCode;
use Infocyph\Runwire\Http\Http3\Http3Exception;

final class EncoderStreamDecoder
{
    private string $buffer = '';

    public function __construct(
        private readonly DynamicTable $table,
        private readonly int $maxLiteralBytes = 65_536,
        private readonly int $maxBufferedBytes = 131_072,
    ) {}

    public function bufferedBytes(): int
    {
        return strlen($this->buffer);
    }

    public function push(string $bytes): int
    {
        $this->buffer .= $bytes;
        if (strlen($this->buffer) > $this->maxBufferedBytes) {
            throw new Http3Exception(
                ErrorCode::QPACK_ENCODER_STREAM_ERROR,
                'QPACK encoder stream buffer exceeds configured limit.',
            );
        }

        $offset = 0;
        $insertions = 0;
        while (($next = $this->decodeInstruction($offset)) !== null) {
            [$offset, $inserted] = $next;
            $insertions += $inserted;
        }

        if ($offset > 0) {
            $this->buffer = substr($this->buffer, $offset);
        }

        return $insertions;
    }

    /** @return array{0: int, 1: int}|null */
    private function decodeInstruction(int $offset): ?array
    {
        if (!isset($this->buffer[$offset])) {
            return null;
        }

        $first = ord($this->buffer[$offset]);

        return match (true) {
            ($first & 0x80) !== 0 => $this->insertNameReference($offset),
            ($first & 0xC0) === 0x40 => $this->insertLiteralName($offset),
            ($first & 0xE0) === 0x20 => $this->setCapacity($offset),
            default => $this->duplicate($offset),
        };
    }

    /** @return array{0: int, 1: int}|null */
    private function duplicate(int $offset): ?array
    {
        $decoded = IntegerCodec::tryDecode(
            $this->buffer,
            $offset,
            5,
            ErrorCode::QPACK_ENCODER_STREAM_ERROR,
        );
        if ($decoded === null) {
            return null;
        }

        [$relative, $next] = $decoded;
        $entry = $this->table->entry(
            $this->table->relativeToInsertCount($relative),
            ErrorCode::QPACK_ENCODER_STREAM_ERROR,
        );
        $this->table->insertPeer($entry['name'], $entry['value']);

        return [$next, 1];
    }

    /** @return array{0: int, 1: int}|null */
    private function insertLiteralName(int $offset): ?array
    {
        $name = StringCodec::tryDecode(
            $this->buffer,
            $offset,
            5,
            0x20,
            $this->maxLiteralBytes,
            ErrorCode::QPACK_ENCODER_STREAM_ERROR,
        );
        if ($name === null) {
            return null;
        }

        [$nameValue, $next] = $name;
        $value = StringCodec::tryDecode(
            $this->buffer,
            $next,
            7,
            0x80,
            $this->maxLiteralBytes,
            ErrorCode::QPACK_ENCODER_STREAM_ERROR,
        );
        if ($value === null) {
            return null;
        }

        [$fieldValue, $next] = $value;
        $this->table->insertPeer($nameValue, $fieldValue);

        return [$next, 1];
    }

    /** @return array{0: int, 1: int}|null */
    private function insertNameReference(int $offset): ?array
    {
        $static = (ord($this->buffer[$offset]) & 0x40) !== 0;
        $decoded = IntegerCodec::tryDecode(
            $this->buffer,
            $offset,
            6,
            ErrorCode::QPACK_ENCODER_STREAM_ERROR,
        );
        if ($decoded === null) {
            return null;
        }

        [$index, $next] = $decoded;
        $value = StringCodec::tryDecode(
            $this->buffer,
            $next,
            7,
            0x80,
            $this->maxLiteralBytes,
            ErrorCode::QPACK_ENCODER_STREAM_ERROR,
        );
        if ($value === null) {
            return null;
        }

        [$fieldValue, $next] = $value;
        $name = $static
            ? StaticTable::entry($index, ErrorCode::QPACK_ENCODER_STREAM_ERROR)[0]
            : $this->table->entry(
                $this->table->relativeToInsertCount($index),
                ErrorCode::QPACK_ENCODER_STREAM_ERROR,
            )['name'];
        $this->table->insertPeer($name, $fieldValue);

        return [$next, 1];
    }

    /** @return array{0: int, 1: int}|null */
    private function setCapacity(int $offset): ?array
    {
        $decoded = IntegerCodec::tryDecode(
            $this->buffer,
            $offset,
            5,
            ErrorCode::QPACK_ENCODER_STREAM_ERROR,
        );
        if ($decoded === null) {
            return null;
        }

        [$capacity, $next] = $decoded;
        $this->table->setCapacityPeer($capacity);

        return [$next, 0];
    }
}
