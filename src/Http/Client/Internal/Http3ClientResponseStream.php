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
use Infocyph\Runwire\Http\Http3\Qpack\ReadyFieldSection;
use Infocyph\Runwire\Http\Internal\ContentLengthParser;
use Infocyph\Runwire\Http\Internal\ResponseSemantics;
use Throwable;

/** @internal Bounded incremental HTTP/3 response stream for an outbound request. */
final class Http3ClientResponseStream
{
    private readonly Http3ClientWireCodec $codec;

    private readonly FrameParser $frames;

    private readonly Closure $onBody;

    private readonly Closure $onHeaders;

    private readonly ?Closure $onTrailers;

    private bool $eofWhileBlocked = false;

    private ?int $expectedBodyBytes = null;

    private bool $finished = false;

    private bool $headersSeen = false;

    private ?string $qpackWaiting = null;

    private int $queuedFrameBytes = 0;

    /** @var list<Frame> */
    private array $queuedFrames = [];

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
        ?Http3ClientWireCodec $codec = null,
        private readonly bool $headRequest = false,
    ) {
        if ($streamId < 0 || $maxBodyBytes < 0 || $maxFrameBytes < 1) {
            throw new \InvalidArgumentException('Invalid outbound HTTP/3 response limits.');
        }
        $this->onHeaders = Closure::fromCallable($onHeaders);
        $this->onBody = Closure::fromCallable($onBody);
        $this->onTrailers = $onTrailers === null ? null : Closure::fromCallable($onTrailers);
        $this->frames = new FrameParser($maxFrameBytes);
        $this->codec = $codec ?? new Http3ClientWireCodec();
    }

    /** Require a complete response at peer FIN. */
    public function finish(): void
    {
        if ($this->qpackWaiting !== null) {
            $this->eofWhileBlocked = true;

            return;
        }
        if ($this->finished || !$this->headersSeen || $this->frames->bufferedBytes() > 0) {
            throw new Http3Exception(ErrorCode::REQUEST_INCOMPLETE, 'HTTP/3 response ended without complete HEADERS and frame boundaries.');
        }
        if ($this->expectedBodyBytes !== null && $this->receivedBodyBytes !== $this->expectedBodyBytes) {
            throw new Http3Exception(ErrorCode::MESSAGE_ERROR, 'HTTP/3 response Content-Length mismatch.');
        }
        $this->finished = true;
    }

    /** Whether the response fully completed after HTTP/3 FIN and QPACK unblocking. */
    public function isFinished(): bool
    {
        return $this->finished;
    }

    /** Accept bytes from a worker-owned QUIC request stream. */
    public function push(string $bytes): void
    {
        if ($this->finished) {
            throw new Http3Exception(ErrorCode::FRAME_UNEXPECTED, 'Response bytes received after QUIC FIN.');
        }
        foreach ($this->frames->push($bytes) as $frame) {
            $this->dispatchFrame($frame);
        }
    }

    /** Finish a previously blocked field section and preserve peer frame ordering. */
    public function resumeQpack(ReadyFieldSection $ready): void
    {
        if ($ready->streamId !== $this->streamId || $this->qpackWaiting === null) {
            throw new Http3Exception(ErrorCode::QPACK_DECOMPRESSION_FAILED, 'Unexpected QPACK unblocking for response stream.');
        }
        $kind = $this->qpackWaiting;
        $this->qpackWaiting = null;
        if ($kind === 'headers') {
            $this->acceptValidatedHeaders($this->codec->decodeReadyHeaders($ready->section));
        } else {
            $this->acceptValidatedTrailers($this->codec->decodeReadyTrailers($ready->section));
        }

        $frames = $this->queuedFrames;
        $this->queuedFrames = [];
        $this->queuedFrameBytes = 0;
        foreach ($frames as $frame) {
            $this->dispatchFrame($frame);
        }
        if ($this->eofWhileBlocked && $this->qpackWaiting === null) {
            $this->eofWhileBlocked = false;
            $this->finish();
        }
    }

    private function acceptData(Frame $frame): void
    {
        if ($this->trailersSeen) {
            throw new Http3Exception(ErrorCode::FRAME_UNEXPECTED, 'HTTP/3 DATA is forbidden after response trailers.');
        }
        $length = strlen($frame->payload);
        if ($length > $this->maxBodyBytes - $this->receivedBodyBytes) {
            throw new Http3Exception(ErrorCode::EXCESSIVE_LOAD, 'HTTP/3 response body exceeds configured limit.');
        }
        if ($this->expectedBodyBytes !== null && $length > $this->expectedBodyBytes - $this->receivedBodyBytes) {
            throw new Http3Exception(ErrorCode::MESSAGE_ERROR, 'HTTP/3 response content exceeds its declared or permitted length.');
        }
        $this->receivedBodyBytes += $length;
        ($this->onBody)($frame->payload);
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
            $this->acceptData($frame);

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
        $response = $this->codec->tryDecodeResponseHeaders($frame->payload, $this->streamId);
        if ($response === null) {
            $this->qpackWaiting = 'headers';

            return;
        }
        $this->acceptValidatedHeaders($response);
    }

    private function acceptTrailers(Frame $frame): void
    {
        if ($this->trailersSeen) {
            throw new Http3Exception(ErrorCode::FRAME_UNEXPECTED, 'Duplicate HTTP/3 response trailers.');
        }
        $trailers = $this->codec->tryDecodeResponseTrailers($frame->payload, $this->streamId);
        if ($trailers === null) {
            $this->qpackWaiting = 'trailers';

            return;
        }
        $this->acceptValidatedTrailers($trailers);
    }

    /** @param array{status:int, headers:Headers} $response */
    private function acceptValidatedHeaders(array $response): void
    {
        $headers = $response['headers'];
        $lengths = $headers->all('content-length');
        if (count($lengths) > 1) {
            throw new Http3Exception(ErrorCode::MESSAGE_ERROR, 'Conflicting HTTP/3 response Content-Length headers.');
        }

        try {
            $length = isset($lengths[0]) ? ContentLengthParser::parse($lengths[0]) : null;
            ResponseSemantics::assertContentLength($response['status'], $length);
        } catch (Throwable $error) {
            throw new Http3Exception(ErrorCode::MESSAGE_ERROR, 'Invalid HTTP/3 response Content-Length.', $error);
        }
        if (ResponseSemantics::suppressesBody($this->headRequest, $response['status'])) {
            $this->expectedBodyBytes = 0;
        } elseif ($length !== null && $length > $this->maxBodyBytes) {
            throw new Http3Exception(ErrorCode::MESSAGE_ERROR, 'Invalid HTTP/3 response Content-Length.');
        } else {
            $this->expectedBodyBytes = $length;
        }
        $this->headersSeen = true;
        ($this->onHeaders)($response['status'], $headers);
    }

    private function acceptValidatedTrailers(Headers $trailers): void
    {
        $this->trailersSeen = true;
        if ($this->onTrailers !== null) {
            ($this->onTrailers)($trailers);
        }
    }

    /** Hold later frames behind a blocked QPACK HEADERS section. */
    private function dispatchFrame(Frame $frame): void
    {
        if ($this->qpackWaiting !== null) {
            $size = strlen($frame->payload);
            if ($size > 1_048_576 - $this->queuedFrameBytes || count($this->queuedFrames) >= 128) {
                throw new Http3Exception(ErrorCode::EXCESSIVE_LOAD, 'Blocked HTTP/3 response frames exceeded budget.');
            }
            $this->queuedFrameBytes += $size;
            $this->queuedFrames[] = $frame;

            return;
        }
        $this->acceptFrame($frame);
    }
}
