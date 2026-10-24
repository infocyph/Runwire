<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Client\Internal;

use Infocyph\Runwire\Http\Headers;
use Infocyph\Runwire\Http\Http2\Enum\FrameType;
use Infocyph\Runwire\Http\Http2\Frame;
use Infocyph\Runwire\Http\Http2\Hpack\Decoder;
use InvalidArgumentException;
use RuntimeException;

/**
 * @internal One bounded HTTP/2 response stream for an explicit odd client stream ID; transport and flow credit
 * remain the caller's responsibility. No trailers, padding or push support.
 */
final class Http2ClientResponseParser
{
    private readonly Decoder $decoder;

    private bool $awaitingContinuation = false;

    private int $bodyBytes = 0;

    private bool $done = false;

    private bool $endStreamOnHeaders = false;

    private string $headerBlock = '';

    private bool $headersSeen = false;

    /** Create an independently bounded response-stream decoder and callback gate. */
    public function __construct(
        private readonly int $maxHeaderBlockBytes = 65_536,
        private readonly int $maxBodyBytes = 1_048_576,
        private readonly int $streamId = 1,
        ?Decoder $sharedDecoder = null,
    ) {
        if ($maxHeaderBlockBytes < 1 || $maxHeaderBlockBytes > 1_048_576
            || $maxBodyBytes < 0 || $maxBodyBytes > 16_777_216
            || $streamId < 1 || $streamId > 0x7FFF_FFFF || $streamId % 2 === 0) {
            throw new InvalidArgumentException('HTTP/2 client response memory limits are invalid.');
        }

        $this->decoder = $sharedDecoder ?? new Decoder(maxHeaderListBytes: $maxHeaderBlockBytes);
    }

    /** Response ownership terminates only after validated END_STREAM. */
    public function done(): bool
    {
        return $this->done;
    }

    /**
     * Decode one validated response-stream frame; no incomplete header block escapes.
     *
     * @param callable(int,Headers):void $onHeaders
     * @param callable(string):void $onBody
     */
    public function push(Frame $frame, callable $onHeaders, callable $onBody): void
    {
        if ($this->done || $frame->streamId !== $this->streamId) {
            throw new RuntimeException('Unexpected terminal or foreign HTTP/2 response stream.');
        }
        if ($this->awaitingContinuation && $frame->knownType() !== FrameType::CONTINUATION) {
            throw new RuntimeException('HTTP/2 frame interleaved with an incomplete header block.');
        }

        match ($frame->knownType()) {
            FrameType::HEADERS => $this->headers($frame, $onHeaders),
            FrameType::CONTINUATION => $this->continuation($frame, $onHeaders),
            FrameType::DATA => $this->data($frame, $onBody),
            default => throw new RuntimeException('Unsupported HTTP/2 response stream frame.'),
        };
    }

    private function appendHeader(string $fragment): void
    {
        if (strlen($fragment) > $this->maxHeaderBlockBytes - strlen($this->headerBlock)) {
            throw new RuntimeException('HTTP/2 response header block exceeds configured memory limit.');
        }
        $this->headerBlock .= $fragment;
    }

    /** @param callable(int,Headers):void $onHeaders */
    private function completeHeaders(callable $onHeaders): void
    {
        $pairs = $this->decoder->decode($this->headerBlock);
        $this->headerBlock = '';
        $status = null;
        $normal = [];
        $regularSeen = false;
        foreach ($pairs as [$name, $value]) {
            if ($name === ':status' && !$regularSeen && $status === null
                && preg_match('/^[1-5][0-9]{2}$/D', $value) === 1) {
                $status = (int) $value;

                continue;
            }
            if (str_starts_with($name, ':') || $name !== strtolower($name)
                || in_array($name, ['connection', 'keep-alive', 'proxy-connection', 'transfer-encoding', 'upgrade'], true)) {
                throw new RuntimeException('HTTP/2 response contains invalid pseudo or connection headers.');
            }
            $regularSeen = true;
            $normal[$name][] = $value;
        }
        if ($status === null || $status < 200) {
            throw new RuntimeException('HTTP/2 response requires a terminal :status pseudo-header.');
        }

        $this->headersSeen = true;
        ($onHeaders)($status, Headers::fromArray($normal));
        if ($this->endStreamOnHeaders) {
            $this->done = true;
        }
    }

    /** @param callable(int,Headers):void $onHeaders */
    private function continuation(Frame $frame, callable $onHeaders): void
    {
        if (!$this->awaitingContinuation || ($frame->flags & ~0x4) !== 0) {
            throw new RuntimeException('HTTP/2 orphan CONTINUATION or invalid flags.');
        }
        $this->appendHeader($frame->payload);
        if ($frame->hasFlag(0x4)) {
            $this->awaitingContinuation = false;
            $this->completeHeaders($onHeaders);
        }
    }

    /** @param callable(string):void $onBody */
    private function data(Frame $frame, callable $onBody): void
    {
        if (!$this->headersSeen || ($frame->flags & ~0x1) !== 0) {
            throw new RuntimeException('HTTP/2 DATA requires complete headers and supported flags.');
        }
        $bytes = strlen($frame->payload);
        if ($bytes > $this->maxBodyBytes - $this->bodyBytes) {
            throw new RuntimeException('HTTP/2 response body exceeds configured memory limit.');
        }
        $this->bodyBytes += $bytes;
        if ($bytes > 0) {
            ($onBody)($frame->payload);
        }
        if ($frame->hasFlag(0x1)) {
            $this->done = true;
        }
    }

    /** @param callable(int,Headers):void $onHeaders */
    private function headers(Frame $frame, callable $onHeaders): void
    {
        if ($this->headersSeen || $this->awaitingContinuation || ($frame->flags & ~0x5) !== 0) {
            throw new RuntimeException('HTTP/2 duplicate HEADERS or unsupported flags.');
        }
        $this->endStreamOnHeaders = $frame->hasFlag(0x1);
        $this->appendHeader($frame->payload);
        $this->awaitingContinuation = !$frame->hasFlag(0x4);
        if (!$this->awaitingContinuation) {
            $this->completeHeaders($onHeaders);
        }
    }
}
