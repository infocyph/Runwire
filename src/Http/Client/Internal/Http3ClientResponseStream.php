<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Client\Internal;

use Closure;
use Infocyph\Runwire\Http\Headers;
use Infocyph\Runwire\Http\Http3\Enum\ErrorCode;
use Infocyph\Runwire\Http\Http3\Enum\FrameType;
use Infocyph\Runwire\Http\Http3\Frame;
use Infocyph\Runwire\Http\Http3\FrameParser;
use Infocyph\Runwire\Http\Http3\Http3Exception;

/** @internal Bounded incremental HTTP/3 response stream for an outbound request. */
final class Http3ClientResponseStream
{
    private readonly Http3ClientWireCodec $codec;

    private readonly FrameParser $frames;

    private readonly Closure $onBody;

    private readonly Closure $onHeaders;

    private readonly ?Closure $onTrailers;

    private ?int $expectedBodyBytes = null;

    private bool $finished = false;

    private bool $headersSeen = false;

    private int $receivedBodyBytes = 0;

    private bool $trailersSeen = false;

    /**
     * @param callable(int,Headers):void $onHeaders
     * @param callable(string):void $onBody
     * @param null|callable(Headers):void $onTrailers
     */
    public function __construct(
        private readonly int $streamId,
        callable $onHeaders,
        callable $onBody,
        private readonly int $maxBodyBytes = 1_048_576,
        int $maxFrameBytes = 65_536,
        ?callable $onTrailers = null,
    ) {
        if ($streamId < 0 || $maxBodyBytes < 0 || $maxFrameBytes < 1) {
            throw new \InvalidArgumentException('Invalid outbound HTTP/3 response limits.');
        }
        $this->onHeaders = Closure::fromCallable($onHeaders);
        $this->onBody = Closure::fromCallable($onBody);
        $this->onTrailers = $onTrailers === null ? null : Closure::fromCallable($onTrailers);
        $this->frames = new FrameParser($maxFrameBytes);
        $this->codec = new Http3ClientWireCodec();
    }

    /** Require a complete response at peer FIN. */
    public function finish(): void
    {
        if ($this->finished || !$this->headersSeen || $this->frames->bufferedBytes() > 0) {
            throw new Http3Exception(ErrorCode::REQUEST_INCOMPLETE, 'HTTP/3 response ended without complete HEADERS and frame boundaries.');
        }
        if ($this->expectedBodyBytes !== null && $this->receivedBodyBytes !== $this->expectedBodyBytes) {
            throw new Http3Exception(ErrorCode::MESSAGE_ERROR, 'HTTP/3 response Content-Length mismatch.');
        }
        $this->finished = true;
    }

    /** Accept bytes from a worker-owned QUIC request stream. */
    public function push(string $bytes): void
    {
        if ($this->finished) {
            throw new Http3Exception(ErrorCode::FRAME_UNEXPECTED, 'Response bytes received after QUIC FIN.');
        }
        foreach ($this->frames->push($bytes) as $frame) {
            $this->acceptFrame($frame);
        }
    }

    private function acceptFrame(Frame $frame): void
    {
        $type = $frame->knownType();
        if (!$this->headersSeen) {
            if ($type !== FrameType::HEADERS) {
                throw new Http3Exception(ErrorCode::FRAME_UNEXPECTED, 'HTTP/3 response must start with HEADERS.');
            }
            $this->acceptHeaders($frame);

            return;
        }
        if ($type === FrameType::DATA) {
            if ($this->trailersSeen) {
                throw new Http3Exception(ErrorCode::FRAME_UNEXPECTED, 'HTTP/3 DATA is forbidden after response trailers.');
            }
            $length = strlen($frame->payload);
            if ($length > $this->maxBodyBytes - $this->receivedBodyBytes
                || ($this->expectedBodyBytes !== null && $length > $this->expectedBodyBytes - $this->receivedBodyBytes)) {
                throw new Http3Exception(ErrorCode::EXCESSIVE_LOAD, 'HTTP/3 response body exceeds configured limit.');
            }
            $this->receivedBodyBytes += $length;
            ($this->onBody)($frame->payload);

            return;
        }
        if ($type === FrameType::HEADERS) {
            $this->acceptTrailers($frame);

            return;
        }
        if (in_array($type, [FrameType::SETTINGS, FrameType::GOAWAY, FrameType::PUSH_PROMISE], true)) {
            throw new Http3Exception(ErrorCode::FRAME_UNEXPECTED, 'Forbidden HTTP/3 control frame on a response stream.');
        }
        // Unknown extension frames are allowed and ignored by HTTP/3.
    }

    private function acceptHeaders(Frame $frame): void
    {
        $response = $this->codec->decodeResponseHeaders($frame->payload, $this->streamId);
        $headers = $response['headers'];
        $lengths = $headers->all('content-length');
        if (count($lengths) > 1) {
            throw new Http3Exception(ErrorCode::MESSAGE_ERROR, 'Conflicting HTTP/3 response Content-Length headers.');
        }
        $length = $lengths[0] ?? null;
        if ($length !== null) {
            if (preg_match('/^(0|[1-9][0-9]*)$/D', $length) !== 1
                || strlen($length) > 16 || (float) $length > $this->maxBodyBytes) {
                throw new Http3Exception(ErrorCode::MESSAGE_ERROR, 'Invalid HTTP/3 response Content-Length.');
            }
            $this->expectedBodyBytes = (int) $length;
        }
        $this->headersSeen = true;
        ($this->onHeaders)($response['status'], $headers);
    }

    private function acceptTrailers(Frame $frame): void
    {
        if ($this->trailersSeen) {
            throw new Http3Exception(ErrorCode::FRAME_UNEXPECTED, 'Duplicate HTTP/3 response trailers.');
        }
        $trailers = $this->codec->decodeResponseTrailers($frame->payload, $this->streamId);
        $this->trailersSeen = true;
        if ($this->onTrailers !== null) {
            ($this->onTrailers)($trailers);
        }
    }
}
