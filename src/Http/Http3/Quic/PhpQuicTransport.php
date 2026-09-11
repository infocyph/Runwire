<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Http3\Quic;

use Infocyph\Runwire\Http\Http3\Internal\Http3TransportInterface;
use InvalidArgumentException;
use LogicException;

final class PhpQuicTransport implements Http3TransportInterface
{
    /** @var array<int, PhpQuicStream> */
    private array $requestStreams = [];

    public function __construct(private readonly PhpQuicStream $qpackEncoderStream)
    {
        if ($qpackEncoderStream->bidirectional()) {
            throw new InvalidArgumentException('HTTP/3 QPACK encoder stream must be unidirectional.');
        }
    }

    public function finishRequestStream(int $streamId): void
    {
        $this->requestStream($streamId)->end();
    }

    public function registerRequestStream(PhpQuicStream $stream): void
    {
        $streamId = $stream->id();
        if (!$stream->bidirectional() || ($streamId & 0x03) !== 0) {
            throw new InvalidArgumentException('HTTP/3 request stream must be client-initiated and bidirectional.');
        }
        if (isset($this->requestStreams[$streamId])) {
            throw new LogicException('HTTP/3 request stream is already registered with the QUIC transport.');
        }

        $this->requestStreams[$streamId] = $stream;
    }

    public function releaseRequestStream(int $streamId): void
    {
        unset($this->requestStreams[$streamId]);
    }

    public function writeQpackEncoder(string $bytes): int
    {
        return $this->qpackEncoderStream->write($bytes);
    }

    public function writeRequestStream(int $streamId, string $bytes): int
    {
        return $this->requestStream($streamId)->write($bytes);
    }

    private function requestStream(int $streamId): PhpQuicStream
    {
        return $this->requestStreams[$streamId]
            ?? throw new LogicException('HTTP/3 request stream is not registered with the QUIC transport.');
    }
}
