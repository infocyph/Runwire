<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Http3\Internal;

use Infocyph\Runwire\Http\Headers;
use Infocyph\Runwire\Http\Http3\ErrorCode;
use Infocyph\Runwire\Http\Http3\Frame;
use Infocyph\Runwire\Http\Http3\FrameParser;
use Infocyph\Runwire\Http\Http3\FrameType;
use Infocyph\Runwire\Http\Http3\Http3Exception;
use Infocyph\Runwire\Http\Http3\Http3Limits;
use Infocyph\Runwire\Http\Http3\Qpack\DecodedFieldSection;
use Infocyph\Runwire\Http\Http3\Qpack\Decoder;
use Infocyph\Runwire\Http\Internal\HeaderValidationException;
use Infocyph\Runwire\Http\Internal\RequestHeaderValidator;
use Infocyph\Runwire\Http\Internal\StreamingRequestBody;
use Infocyph\Runwire\Http\Internal\ValidatedRequestHead;
use Infocyph\Runwire\Http\RequestBodyInterface;

final class RequestStream
{
    private readonly StreamingRequestBody $body;

    private readonly FrameParser $parser;

    private readonly RequestHeaderValidator $validator;

    private bool $blocked = false;

    private int $blockedFrameBytes = 0;

    /** @var list<Frame> */
    private array $blockedFrames = [];

    private bool $blockedOnTrailers = false;

    private bool $cancelled = false;

    private bool $finReceived = false;

    private bool $finished = false;

    private ?ValidatedRequestHead $head = null;

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
        $this->body = new StreamingRequestBody(
            $limits->bodyLowWatermarkBytes,
            $limits->bodyHighWatermarkBytes,
            $limits->maxPendingBodyBytesPerStream,
            $onBodyRelief ?? static function (): void {
            },
            $onBodyConsumed,
        );
    }

    public function blocked(): bool
    {
        return $this->blocked;
    }

    public function body(): RequestBodyInterface
    {
        return $this->body;
    }

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
        $this->cancelled = true;
        $this->body->cancel();
    }

    public function finish(): void
    {
        $this->assertOpen();
        if ($this->finReceived) {
            throw new Http3Exception(ErrorCode::FRAME_UNEXPECTED, 'HTTP/3 request stream FIN was already received.');
        }

        $this->finReceived = true;
        if ($this->parser->bufferedBytes() > 0) {
            throw new Http3Exception(ErrorCode::FRAME_ERROR, 'HTTP/3 request stream ended during an incomplete frame.');
        }
        if ($this->blocked) {
            return;
        }

        $this->completeFinish();
    }

    public function finished(): bool
    {
        return $this->finished;
    }

    public function head(): ?ValidatedRequestHead
    {
        return $this->head;
    }

    public function pressured(): bool
    {
        return $this->body->pressured();
    }

    public function push(string $bytes): void
    {
        $this->assertOpen();
        if ($this->finReceived) {
            throw new Http3Exception(ErrorCode::FRAME_UNEXPECTED, 'HTTP/3 request bytes arrived after stream FIN.');
        }

        foreach ($this->parser->push($bytes) as $frame) {
            if ($this->blocked) {
                $this->bufferBlockedFrame($frame);

                continue;
            }

            $this->processFrame($frame);
        }
    }

    public function receivedBodyBytes(): int
    {
        return $this->receivedBodyBytes;
    }

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

        $frames = $this->blockedFrames;
        $this->blockedFrames = [];
        $this->blockedFrameBytes = 0;

        foreach ($frames as $frame) {
            if ($this->blocked) {
                $this->bufferBlockedFrame($frame);

                continue;
            }

            $this->processFrame($frame);
        }

        if ($this->finReceived && !$this->blocked) {
            $this->completeFinish();
        }
    }

    public function streamId(): int
    {
        return $this->streamId;
    }

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
        if ($bytes > $this->body->capacity()) {
            throw new Http3Exception(ErrorCode::EXCESSIVE_LOAD, 'HTTP/3 request body buffer capacity was exceeded.');
        }
        if (
            $this->head->contentLength !== null
            && $bytes > $this->head->contentLength - $this->receivedBodyBytes
        ) {
            throw new Http3Exception(ErrorCode::MESSAGE_ERROR, 'HTTP/3 request body exceeds Content-Length.');
        }

        if ($payload !== '') {
            $this->body->push($payload);
            $this->receivedBodyBytes += $bytes;
        }
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
}
