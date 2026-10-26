<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Client\Internal;

use Infocyph\Runwire\Http\Http3\Enum\ErrorCode;
use Infocyph\Runwire\Http\Http3\Enum\StreamType;
use Infocyph\Runwire\Http\Http3\Http3Exception;
use Infocyph\Runwire\Http\Http3\Qpack\ReadyFieldSection;

/** @internal Dispatches peer auxiliary streams without owning a request lifecycle. */
final class Http3ClientPeerAuxiliary
{
    private int $unknownStreams = 0;

    /** Share the same bounded QPACK decoder across peer streams. */
    public function __construct(private readonly Http3ClientWireCodec $codec) {}

    /**
     * Validate peer encoder/decoder stream bytes and flag control-loop liveness.
     *
     * @return array{watch:bool,completed:bool}
     */
    public function accept(
        int $type,
        string $bytes,
        bool $eof,
        ?Http3ClientResponseStream $response,
        int $responseStream,
        ?callable $releaseSibling = null,
    ): array {
        $completed = false;
        if ($type === StreamType::QPACK_ENCODER->value) {
            $completed = $this->acceptEncoder($bytes, $response, $responseStream, $releaseSibling);
        } elseif ($type === StreamType::QPACK_DECODER->value) {
            $this->codec->receivePeerDecoderInstructions($bytes);
        } elseif ($type === StreamType::PUSH->value) {
            throw new Http3Exception(ErrorCode::ID_ERROR, 'Peer push is forbidden without MAX_PUSH_ID.');
        } elseif (++$this->unknownStreams > 8) {
            throw new Http3Exception(ErrorCode::EXCESSIVE_LOAD, 'Excessive unrecognized HTTP/3 peer streams.');
        }

        $watch = in_array($type, [StreamType::QPACK_ENCODER->value, StreamType::QPACK_DECODER->value], true);
        if ($watch && $eof) {
            throw new Http3Exception(ErrorCode::CLOSED_CRITICAL_STREAM, 'Peer QPACK critical stream ended prematurely.');
        }

        return ['watch' => $watch, 'completed' => $completed];
    }

    /** @param null|callable(ReadyFieldSection):bool $releaseSibling */
    private function acceptEncoder(
        string $bytes,
        ?Http3ClientResponseStream $response,
        int $responseStream,
        ?callable $releaseSibling,
    ): bool {
        $completed = false;
        foreach ($this->codec->receivePeerEncoderInstructions($bytes) as $ready) {
            if ($response !== null && $ready->streamId === $responseStream) {
                $response->resumeQpack($ready);
                $completed = $completed || $response->isFinished();

                continue;
            }
            if ($releaseSibling === null || !$releaseSibling($ready)) {
                throw new Http3Exception(ErrorCode::QPACK_DECOMPRESSION_FAILED, 'QPACK released an unknown response stream.');
            }
        }

        return $completed;
    }
}
