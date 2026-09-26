<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Http3\Internal;

use Closure;
use Infocyph\Runwire\Http\Headers;
use Infocyph\Runwire\Http\Http3\Enum\ErrorCode;
use Infocyph\Runwire\Http\Http3\Enum\FrameType;
use Infocyph\Runwire\Http\Http3\Frame;
use Infocyph\Runwire\Http\Http3\FrameParser;
use Infocyph\Runwire\Http\Http3\Http3Exception;
use Infocyph\Runwire\Http\Http3\Http3Limits;
use Infocyph\Runwire\Http\Http3\Qpack\DecodedFieldSection;
use Infocyph\Runwire\Http\Http3\Qpack\Decoder;
use Infocyph\Runwire\Http\Internal\HeaderValidationException;
use Infocyph\Runwire\Http\Internal\RequestHeaderValidator;
use Infocyph\Runwire\Http\Internal\StreamingRequestBody;
use Infocyph\Runwire\Http\Internal\ValidatedRequestHead;
use Infocyph\Runwire\Http\RequestBodyInterface;

/**
 * Parses one HTTP/3 request stream and coordinates QPACK, body, and trailer state.
 */
final class RequestStream
{
    private readonly StreamingRequestBody $body;

    private readonly Closure $onBodyRelief;

    private readonly FrameParser $parser;

    private readonly RequestHeaderValidator $validator;

    private bool $blocked = false;

    private int $blockedFrameBytes = 0;

    /** @var list<Frame> */
    private array $blockedFrames = [];

    private bool $blockedOnTrailers = false;

    private bool $cancelled = false;

    private bool $finished = false;

    private bool $finReceived = false;

    private ?ValidatedRequestHead $head = null;

    private string $pendingData = '';

    private int $receivedBodyBytes = 0;

    private ?Headers $trailers = null;

    private bool $trailersReceived = false;

    /**
     * @param callable(): void|null $onBodyRelief
     * @param callable(int): void|null $onBodyConsumed
     */
    public function __construct(
        private readonly int $streamId,
        private readonly Decoder $decoder,
        private readonly Http3Limits $limits,
        ?callable $onBodyRelief = null,
        ?callable $onBodyConsumed = null,
    ) {
        if ($streamId < 0 || ($streamId & 0x03) !== 0) {
            throw new \InvalidArgumentException('HTTP/3 request stream must be client-initiated and bidirectional.');
        }

        $this->parser = new FrameParser($limits->maxFramePayloadBytes);
        $this->validator = new RequestHeaderValidator('HTTP/3');
        $this->onBodyRelief = Closure::fromCallable($onBodyRelief ?? static function (): void {});
        $this->body = new StreamingRequestBody(
            $limits->bodyLowWatermarkBytes,
            $limits->bodyHighWatermarkBytes,
            $limits->maxPendingBodyBytesPerStream,
            function (): void {
                $this->resumeAfterBodyRelief();
            },
            $onBodyConsumed,
        );
    }

    /**
     * Determine whether request processing is blocked on QPACK state.
     */
    public function blocked(): bool
    {
        return $this->blocked;
    }

    /**
     * Return the streaming request body.
     */
    public function body(): RequestBodyInterface
    {
        return $this->body;
    }

    /**
     * Cancel request processing and release blocked QPACK state.
     */
    public function cancel(): void
    {
        if ($this->cancelled || $this->finished) {
            return;
        }

        if ($this->blocked) {
            $this->decoder->cancelStream($this->streamId);
        }

        $this->blockedFrames = [];
        $this->blockedFrameBytes = 0;
        $this->pendingData = '';
        $this->cancelled = true;
        $this->body->cancel();
    }

    /**
     * Mark transport FIN received and complete the request when unblocked.
     */
    public function finish(): void
    {
        $this->assertOpen();
        if ($this->finReceived) {
            throw new Http3Exception(ErrorCode::FRAME_UNEXPECTED, 'HTTP/3 request stream FIN was already received.');
        }

        $this->finReceived = true;
        $this->finishIfReady();
    }

    /**
     * Determine whether request processing is complete.
     */
    public function finished(): bool
    {
        return $this->finished;
    }

    /**
     * Return the validated request head once available.
     */
    public function head(): ?ValidatedRequestHead
    {
        return $this->head;
    }

    /**
     * Determine whether request-body buffering is applying backpressure.
     */
    public function pressured(): bool
    {
        return $this->pendingData !== '' || $this->body->pressured();
    }

    /**
     * Feed request-stream bytes through HTTP/3 frame processing.
     */
    public function push(string $bytes): void
    {
        $this->assertOpen();
        if ($this->finReceived) {
            throw new Http3Exception(ErrorCode::FRAME_UNEXPECTED, 'HTTP/3 request bytes arrived after stream FIN.');
        }

        $this->parser->append($bytes);
        $this->drainFrames();
    }

    /**
     * Return the number of accepted request-body bytes.
     */
    public function receivedBodyBytes(): int
    {
        return $this->receivedBodyBytes;
    }

    /**
     * Resume a request whose QPACK field section has become decodable.
     */
    public function resume(DecodedFieldSection $section): void
    {
        $this->assertOpen();
        if (!$this->blocked) {
            throw new \LogicException('HTTP/3 request stream is not blocked on QPACK.');
        }

        $trailers = $this->blockedOnTrailers;
        $this->blocked = false;
        $this->blockedOnTrailers = false;
        $this->acceptFieldSection($section, $trailers);

        $this->drainBufferedFrames();
        $this->finishIfReady();
    }

    /**
     * Return the QUIC request stream identifier.
     */
    public function streamId(): int
    {
        return $this->streamId;
    }

    /**
     * Return validated request trailers when received.
     */
    public function trailers(): ?Headers
    {
        return $this->trailers;
    }

    private function acceptData(string $payload): void
    {
        if ($this->head === null || $this->trailersReceived) {
            throw new Http3Exception(
                ErrorCode::FRAME_UNEXPECTED,
                'HTTP/3 DATA must follow initial HEADERS and precede trailing HEADERS.',
            );
        }

        $bytes = strlen($payload);
        if ($bytes > $this->limits->maxBodyBytes - $this->receivedBodyBytes) {
            throw new Http3Exception(ErrorCode::EXCESSIVE_LOAD, 'HTTP/3 request body exceeds configured limit.');
        }
        if (
            $this->head->contentLength !== null
            && $bytes > $this->head->contentLength - $this->receivedBodyBytes
        ) {
            throw new Http3Exception(ErrorCode::MESSAGE_ERROR, 'HTTP/3 request body exceeds Content-Length.');
        }

        $this->deliverData($payload);
    }

    private function acceptFieldSection(DecodedFieldSection $section, bool $trailers): void
    {
        try {
            if ($trailers) {
                $this->trailers = $this->validator->trailers($section->fields);
                $this->trailersReceived = true;

                return;
            }

            $this->head = $this->validator->request($section->fields);
        } catch (HeaderValidationException $exception) {
            throw new Http3Exception(ErrorCode::MESSAGE_ERROR, $exception->getMessage(), $exception);
        }
    }

    private function assertOpen(): void
    {
        if ($this->cancelled) {
            throw new Http3Exception(ErrorCode::REQUEST_CANCELLED, 'HTTP/3 request stream is cancelled.');
        }
        if ($this->finished) {
            throw new Http3Exception(ErrorCode::FRAME_UNEXPECTED, 'HTTP/3 request stream is already finished.');
        }
    }

    private function bufferBlockedFrame(Frame $frame): void
    {
        $bytes = strlen($frame->payload) + 16;
        if ($bytes > $this->limits->maxBlockedRequestStreamBytes - $this->blockedFrameBytes) {
            throw new Http3Exception(
                ErrorCode::EXCESSIVE_LOAD,
                'HTTP/3 frames queued behind blocked QPACK state exceed configured limit.',
            );
        }

        $this->blockedFrames[] = $frame;
        $this->blockedFrameBytes += $bytes;
    }

    private function completeFinish(): void
    {
        if ($this->head === null) {
            throw new Http3Exception(ErrorCode::REQUEST_INCOMPLETE, 'HTTP/3 request stream ended before initial HEADERS.');
        }
        if ($this->head->contentLength !== null && $this->receivedBodyBytes !== $this->head->contentLength) {
            throw new Http3Exception(
                ErrorCode::MESSAGE_ERROR,
                'HTTP/3 request body length does not match Content-Length.',
            );
        }

        $this->body->finish($this->trailers);
        $this->finished = true;
    }

    private function deliverData(string $payload): void
    {
        while ($payload !== '') {
            $capacity = $this->body->capacity();
            if ($capacity <= 0 || $this->body->pressured()) {
                $this->pendingData = $payload;

                return;
            }

            $length = min(strlen($payload), $capacity, $this->limits->streamReadChunkBytes);
            $chunk = substr($payload, 0, $length);
            $payload = substr($payload, $length);
            $accepting = $this->body->push($chunk);
            $this->receivedBodyBytes += $length;

            if (!$accepting && $payload !== '') {
                $this->pendingData = $payload;

                return;
            }
        }
    }

    private function drainBufferedFrames(): void
    {
        $frames = $this->blockedFrames;
        $this->blockedFrames = [];
        $this->blockedFrameBytes = 0;

        foreach ($frames as $index => $frame) {
            if ($this->blocked) {
                $this->bufferBlockedFrame($frame);

                continue;
            }

            $this->processFrame($frame);
            if (!$this->pressured()) {
                continue;
            }

            foreach (array_slice($frames, $index + 1) as $remaining) {
                $this->bufferBlockedFrame($remaining);
            }

            return;
        }
    }

    private function drainFrames(): void
    {
        if ($this->pendingData !== '') {
            $pending = $this->pendingData;
            $this->pendingData = '';
            $this->deliverData($pending);
            if ($this->pressured()) {
                return;
            }
        }

        if (!$this->blocked && $this->blockedFrames !== []) {
            $this->drainBufferedFrames();
            if ($this->blocked || $this->pressured()) {
                return;
            }
        }

        while (($frame = $this->parser->shift()) !== null) {
            if ($this->blocked) {
                $this->bufferBlockedFrame($frame);

                continue;
            }

            $this->processFrame($frame);
            if ($this->pressured()) {
                return;
            }
        }
    }

    private function finishIfReady(): void
    {
        if (!$this->finReceived || $this->blocked || $this->pressured()) {
            return;
        }

        $this->drainFrames();
        if ($this->blocked || $this->pressured()) {
            return;
        }
        if ($this->parser->bufferedBytes() > 0) {
            throw new Http3Exception(ErrorCode::FRAME_ERROR, 'HTTP/3 request stream ended during an incomplete frame.');
        }

        $this->completeFinish();
    }

    private function processFrame(Frame $frame): void
    {
        $type = $frame->knownType();
        if ($type === null) {
            return;
        }
        if ($type === FrameType::DATA) {
            $this->acceptData($frame->payload);

            return;
        }
        if ($type === FrameType::HEADERS) {
            $this->processHeaders($frame->payload);

            return;
        }

        throw new Http3Exception(
            ErrorCode::FRAME_UNEXPECTED,
            sprintf('HTTP/3 frame type 0x%x is forbidden on a request stream.', $frame->type),
        );
    }

    private function processHeaders(string $payload): void
    {
        if ($this->trailersReceived) {
            throw new Http3Exception(ErrorCode::FRAME_UNEXPECTED, 'HTTP/3 request stream cannot contain multiple trailer sections.');
        }

        $trailers = $this->head !== null;
        $section = $this->decoder->decode($payload, $this->streamId);
        if ($section === null) {
            $this->blocked = true;
            $this->blockedOnTrailers = $trailers;

            return;
        }

        $this->acceptFieldSection($section, $trailers);
    }

    private function resumeAfterBodyRelief(): void
    {
        if ($this->cancelled || $this->finished) {
            return;
        }

        $this->drainFrames();
        if (!$this->pressured()) {
            ($this->onBodyRelief)();
        }
        $this->finishIfReady();
    }
}
