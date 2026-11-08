<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Http3\Qpack;

/**
 * Encodes local QPACK decoder-stream instructions.
 */
final class DecoderStreamEncoder
{
    /**
     * Encode an Insert Count Increment instruction.
     * @throws \InvalidArgumentException
     */
    public function insertCountIncrement(int $increment): string
    {
        if ($increment <= 0) {
            throw new \InvalidArgumentException('QPACK insert count increment must be positive.');
        }

        return IntegerCodec::encode($increment, 6, 0x00);
    }

    /**
     * Encode a Section Acknowledgement instruction.
     * @throws \InvalidArgumentException
     */
    public function sectionAcknowledgement(int $streamId): string
    {
        return IntegerCodec::encode($streamId, 7, 0x80);
    }

    /**
     * Encode a Stream Cancellation instruction.
     * @throws \InvalidArgumentException
     */
    public function streamCancellation(int $streamId): string
    {
        return IntegerCodec::encode($streamId, 6, 0x40);
    }
}
