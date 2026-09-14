<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Http3\Quic;

use Infocyph\Runwire\Http\Http3\Internal\Http3TransportInterface;
use InvalidArgumentException;
use LogicException;

/**
 * Adapts native QUIC streams to the HTTP/3 response transport contract.
 */
final class PhpQuicTransport implements Http3TransportInterface
{
    /** @var array<int, true> */
    private array $finishedResponses = [];

    /** @var array<int, PhpQuicStream> */
    private array $requestStreams = [];

    /**
     * Create a transport around the local QPACK encoder stream.
     */
    public function __construct(
        private readonly PhpQuicStream $qpackEncoderStream,
        private string $qpackEncoderPreamble = '',
    ) {
        if ($qpackEncoderStream->bidirectional() || ($qpackEncoderStream->id() & 0x03) !== 0x03) {
            throw new InvalidArgumentException('HTTP/3 QPACK encoder stream must be server-initiated and unidirectional.');
        }
    }

    /**
     * Finish the response side of a request stream.
     */
    public function finishRequestStream(int $streamId): void
    {
        $this->requestStream($streamId)->end();
        $this->finishedResponses[$streamId] = true;
    }

    /**
     * Flush the local QPACK encoder stream preamble.
     */
    public function flushQpackEncoderPreamble(): bool
    {
        if ($this->qpackEncoderPreamble === '') {
            return true;
        }

        $written = $this->qpackEncoderStream->write($this->qpackEncoderPreamble);
        $this->qpackEncoderPreamble = substr($this->qpackEncoderPreamble, $written);

        return $this->qpackEncoderPreamble === '';
    }

    /**
     * Return the native QPACK encoder stream object.
     */
    public function qpackEncoderObject(): object
    {
        return $this->qpackEncoderStream->object();
    }

    /**
     * Determine whether QPACK encoder preamble bytes remain pending.
     */
    public function qpackEncoderPreamblePending(): bool
    {
        return $this->qpackEncoderPreamble !== '';
    }

    /**
     * Register a client-initiated bidirectional request stream.
     */
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
        unset($this->finishedResponses[$streamId]);
    }

    /**
     * Release transport state for a request stream.
     */
    public function releaseRequestStream(int $streamId): void
    {
        unset($this->requestStreams[$streamId], $this->finishedResponses[$streamId]);
    }

    /**
     * Determine whether the response side of a stream has finished.
     */
    public function responseFinished(int $streamId): bool
    {
        return isset($this->finishedResponses[$streamId]);
    }

    /**
     * Write bytes to the local QPACK encoder stream.
     */
    public function writeQpackEncoder(string $bytes): int
    {
        if (!$this->flushQpackEncoderPreamble()) {
            return 0;
        }

        return $this->qpackEncoderStream->write($bytes);
    }

    /**
     * Write response bytes to a registered request stream.
     */
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
