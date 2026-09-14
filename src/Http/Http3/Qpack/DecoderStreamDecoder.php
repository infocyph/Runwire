<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Http3\Qpack;

use Infocyph\Runwire\Http\Http3\Enum\ErrorCode;
use Infocyph\Runwire\Http\Http3\Http3Exception;
use Infocyph\Runwire\Http\Http3\Qpack\Enum\DecoderInstructionType;

/**
 * Incrementally decodes peer QPACK decoder-stream instructions.
 */
final class DecoderStreamDecoder
{
    private string $buffer = '';

    /**
     * Create a decoder with a bounded instruction buffer.
     */
    public function __construct(private readonly int $maxBufferedBytes = 65_536) {}

    /** @return list<DecoderInstruction> */
    public function push(string $bytes): array
    {
        $this->buffer .= $bytes;
        if (strlen($this->buffer) > $this->maxBufferedBytes) {
            throw new Http3Exception(
                ErrorCode::QPACK_DECODER_STREAM_ERROR,
                'QPACK decoder stream buffer exceeds configured limit.',
            );
        }

        $instructions = [];
        $offset = 0;
        while (($decoded = $this->decodeInstruction($offset)) !== null) {
            [$instruction, $offset] = $decoded;
            $instructions[] = $instruction;
        }

        if ($offset > 0) {
            $this->buffer = substr($this->buffer, $offset);
        }

        return $instructions;
    }

    /** @return array{0: DecoderInstruction, 1: int}|null */
    private function decode(int $offset, int $prefixBits, DecoderInstructionType $type): ?array
    {
        $decoded = IntegerCodec::tryDecode(
            $this->buffer,
            $offset,
            $prefixBits,
            ErrorCode::QPACK_DECODER_STREAM_ERROR,
        );
        if ($decoded === null) {
            return null;
        }

        [$value, $next] = $decoded;

        return [new DecoderInstruction($type, $value), $next];
    }

    /** @return array{0: DecoderInstruction, 1: int}|null */
    private function decodeInstruction(int $offset): ?array
    {
        if (!isset($this->buffer[$offset])) {
            return null;
        }

        $first = ord($this->buffer[$offset]);
        if (($first & 0x80) !== 0) {
            return $this->decode($offset, 7, DecoderInstructionType::SECTION_ACKNOWLEDGEMENT);
        }
        if (($first & 0x40) !== 0) {
            return $this->decode($offset, 6, DecoderInstructionType::STREAM_CANCELLATION);
        }

        $decoded = $this->decode($offset, 6, DecoderInstructionType::INSERT_COUNT_INCREMENT);
        if ($decoded !== null && $decoded[0]->value === 0) {
            throw new Http3Exception(
                ErrorCode::QPACK_DECODER_STREAM_ERROR,
                'QPACK insert count increment cannot be zero.',
            );
        }

        return $decoded;
    }
}
