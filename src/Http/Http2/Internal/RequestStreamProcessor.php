<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Http2\Internal;

use Closure;
use Infocyph\Runwire\Http\Headers;
use Infocyph\Runwire\Http\Http2\ErrorCode;
use Infocyph\Runwire\Http\Http2\Frame;
use Infocyph\Runwire\Http\Http2\FrameWriter;
use Infocyph\Runwire\Http\Http2\Hpack\Decoder;
use Infocyph\Runwire\Http\Http2\Hpack\HpackException;
use Infocyph\Runwire\Http\Http2\Http2Limits;
use Infocyph\Runwire\Http\Http2\PeerSettings;
use Infocyph\Runwire\Http\HttpRequest;
use Infocyph\Runwire\Http\Internal\StreamingRequestBody;
use Infocyph\Runwire\Http\ProtocolVersion;
use Infocyph\Runwire\Loop\LoopInterface;
use Infocyph\Runwire\Network\Connection;
use Throwable;

final class RequestStreamProcessor
{
    private readonly Closure $connectionFailure;

    private readonly Decoder $decoder;

    private readonly Closure $handler;

    private readonly Closure $streamFailure;

    private readonly Closure $streamRemoved;

    private readonly RequestHeaderValidator $validator;

    private bool $draining = false;

    private ?int $headerBlockTimer = null;

    private int $lastClientStreamId = 0;

    private ?PendingHeaderBlock $pendingHeaders = null;

    /** @var array<int, Http2Stream> */
    private array $streams = [];

    private int $streamsCreated = 0;

    /**
     * @param callable(HttpRequest, \Infocyph\Runwire\Http\Http2\Http2ResponseWriter): void $handler
     * @param callable(ErrorCode, string): void $connectionFailure
     * @param callable(StreamError): void $streamFailure
     * @param callable(): void $streamRemoved
     */
    public function __construct(
        private readonly LoopInterface $loop,
        private readonly Connection $connection,
        private readonly Http2Limits $limits,
        private readonly PeerSettings $peerSettings,
        private readonly FlowController $flow,
        private readonly ResponseScheduler $output,
        callable $handler,
        callable $connectionFailure,
        callable $streamFailure,
        callable $streamRemoved,
    ) {
        $this->decoder = new Decoder(
            $limits->maxDynamicTableBytes,
            $limits->maxHeaderListBytes,
            $limits->maxHeaderCount,
        );
        $this->validator = new RequestHeaderValidator();
        $this->handler = Closure::fromCallable($handler);
        $this->connectionFailure = Closure::fromCallable($connectionFailure);
        $this->streamFailure = Closure::fromCallable($streamFailure);
        $this->streamRemoved = Closure::fromCallable($streamRemoved);
    }

    public function cleanup(): void
    {
        $this->cancelHeaderTimer();
        foreach ($this->streams as $stream) {
            $this->cancelTimer($stream->idleTimer);
            $this->output->discardStream($stream);
            $stream->reset();
        }
        $this->streams = [];
        $this->pendingHeaders = null;
    }

    public function cleanupIfClosed(Http2Stream $stream): void
    {
        if ($stream->dispatching || $stream->state !== \Infocyph\Runwire\Http\Http2\StreamState::CLOSED) {
            return;
        }
        if (!$stream->outbound->isEmpty() || $stream->endPending) {
            return;
        }
        $this->remove($stream);
    }

    public function count(): int
    {
        return count($this->streams);
    }

    public function handleContinuation(Frame $frame): void
    {
        if ($this->pendingHeaders === null) {
            throw new ConnectionError(ErrorCode::PROTOCOL_ERROR, 'Unexpected HTTP/2 CONTINUATION frame.');
        }
        $this->pendingHeaders->append($frame->streamId, $frame->payload);
        if (!$frame->hasFlag(0x4)) {
            return;
        }
        $pending = $this->pendingHeaders;
        $this->pendingHeaders = null;
        $this->cancelHeaderTimer();
        $this->completeHeaderBlock($pending);
    }

    public function handleData(Frame $frame): void
    {
        if ($frame->streamId === 0) {
            throw new ConnectionError(ErrorCode::PROTOCOL_ERROR, 'HTTP/2 DATA requires a non-zero stream ID.');
        }
        $stream = $this->stream($frame->streamId);
        if ($stream === null) {
            $this->handleDataForMissingStream($frame);

            return;
        }
        if (!$stream->remoteOpen()) {
            $this->flow->consumeConnectionReceive(strlen($frame->payload));

            throw new StreamError($stream->id, ErrorCode::STREAM_CLOSED, 'DATA received after remote stream closure.');
        }

        $this->acceptData($stream, $frame);
        if ($frame->hasFlag(0x1)) {
            $this->finishRemote($stream);
        }
    }

    public function handleHeaders(Frame $frame): void
    {
        $this->validateHeadersStream($frame);
        $fragment = $this->headersFragment($frame);
        $existing = $this->stream($frame->streamId);
        $pending = new PendingHeaderBlock(
            $frame->streamId,
            $fragment,
            $frame->hasFlag(0x1),
            $existing !== null && $existing->headersReceived,
            $this->limits,
        );
        if ($frame->hasFlag(0x4)) {
            $this->completeHeaderBlock($pending);

            return;
        }
        $this->pendingHeaders = $pending;
        $this->armHeaderBlockTimer();
    }

    public function hasOpenHeaderBlock(): bool
    {
        return $this->pendingHeaders !== null;
    }

    public function lastClientStreamId(): int
    {
        return $this->lastClientStreamId;
    }

    public function reset(Http2Stream $stream): void
    {
        $this->output->discardStream($stream);
        $stream->reset();
        $this->remove($stream);
    }

    public function setDraining(bool $draining): void
    {
        $this->draining = $draining;
    }

    public function stream(int $id): ?Http2Stream
    {
        return $this->streams[$id] ?? null;
    }

    /** @return array<int, Http2Stream> */
    public function streams(): array
    {
        return $this->streams;
    }

    public function touch(Http2Stream $stream): void
    {
        $this->cancelTimer($stream->idleTimer);
        $streamId = $stream->id;
        $stream->idleTimer = $this->loop->delay($this->limits->streamIdleTimeoutSeconds, function () use ($streamId): void {
            $stream = $this->stream($streamId);
            if ($stream !== null) {
                ($this->streamFailure)(new StreamError($streamId, ErrorCode::CANCEL, 'HTTP/2 stream idle timeout.'));
            }
        });
    }

    private function acceptBodyData(Http2Stream $stream, string $data, int $flowBytes, int $protocolConsumed): void
    {
        $dataBytes = strlen($data);
        if ($dataBytes > $this->limits->maxBodyBytes - $stream->receivedBodyBytes) {
            $this->creditReceive(null, $flowBytes);

            throw new StreamError($stream->id, ErrorCode::CANCEL, 'HTTP/2 request body exceeds configured limit.');
        }
        if ($dataBytes > $stream->body->capacity()) {
            $this->creditReceive(null, $flowBytes);

            throw new StreamError($stream->id, ErrorCode::FLOW_CONTROL_ERROR, 'HTTP/2 request body buffer capacity was exceeded.');
        }
        if ($data !== '') {
            $stream->body->push($data);
            $stream->receivedBodyBytes += $dataBytes;
        }
        if ($protocolConsumed > 0) {
            $this->creditReceive($stream, $protocolConsumed);
        }
    }

    private function acceptData(Http2Stream $stream, Frame $frame): void
    {
        [$data, $flowBytes, $protocolConsumed] = $this->dataPayload($frame);
        $this->flow->consumeReceive($stream, $flowBytes);
        $this->touch($stream);
        if ($stream->discardInbound) {
            $this->creditReceive($stream, $flowBytes);

            return;
        }
        $this->acceptBodyData($stream, $data, $flowBytes, $protocolConsumed);
    }

    private function admit(PendingHeaderBlock $pending, ?int $contentLength): bool
    {
        if ($this->draining) {
            $this->output->sendControl(FrameWriter::rstStream($pending->streamId, ErrorCode::REFUSED_STREAM));

            return false;
        }
        $this->lastClientStreamId = $pending->streamId;
        if (++$this->streamsCreated > $this->limits->maxStreamsPerConnection) {
            throw new ConnectionError(ErrorCode::ENHANCE_YOUR_CALM, 'HTTP/2 stream churn limit exceeded.');
        }
        if ($this->count() >= $this->limits->maxConcurrentStreams) {
            $this->output->sendControl(FrameWriter::rstStream($pending->streamId, ErrorCode::REFUSED_STREAM));

            return false;
        }
        if ($pending->endStream && ($contentLength ?? 0) !== 0) {
            throw new StreamError($pending->streamId, ErrorCode::PROTOCOL_ERROR, 'HTTP/2 END_STREAM conflicts with non-zero Content-Length.');
        }

        return true;
    }

    private function armHeaderBlockTimer(): void
    {
        $this->cancelHeaderTimer();
        $this->headerBlockTimer = $this->loop->delay($this->limits->headerBlockTimeoutSeconds, function (): void {
            $this->headerBlockTimer = null;
            if ($this->pendingHeaders !== null) {
                ($this->connectionFailure)(ErrorCode::PROTOCOL_ERROR, 'HTTP/2 header block did not complete before timeout.');
            }
        });
    }

    private function cancelHeaderTimer(): void
    {
        $this->cancelTimer($this->headerBlockTimer);
        $this->headerBlockTimer = null;
    }

    private function cancelTimer(?int $timer): void
    {
        if ($timer !== null) {
            $this->loop->cancel($timer);
        }
    }

    private function completeHeaderBlock(PendingHeaderBlock $pending): void
    {
        try {
            $fields = $this->decoder->decode($pending->bytes());
        } catch (HpackException $error) {
            throw new ConnectionError(ErrorCode::COMPRESSION_ERROR, $error->getMessage());
        }
        if ($pending->trailers) {
            $this->completeTrailers($pending, $fields);

            return;
        }
        $this->openRequest($pending, $fields);
    }

    /** @param list<array{0: string, 1: string}> $fields */
    private function completeTrailers(PendingHeaderBlock $pending, array $fields): void
    {
        $stream = $this->stream($pending->streamId);
        if ($stream === null) {
            throw new StreamError($pending->streamId, ErrorCode::STREAM_CLOSED, 'Trailers received for a closed stream.');
        }
        if (!$pending->endStream) {
            throw new StreamError($pending->streamId, ErrorCode::PROTOCOL_ERROR, 'HTTP/2 request trailers must end the stream.');
        }

        try {
            $trailers = $this->validator->trailers($fields);
        } catch (HeaderValidationException $error) {
            throw new StreamError($pending->streamId, ErrorCode::PROTOCOL_ERROR, $error->getMessage());
        }
        $stream->trailersReceived = true;
        $this->finishRemote($stream, $trailers);
    }

    private function consumePriorityFields(Frame $frame, string $payload, int $length, int $offset): int
    {
        if ($length - $offset < 5) {
            throw new ConnectionError(ErrorCode::FRAME_SIZE_ERROR, 'HTTP/2 HEADERS priority fields are truncated.');
        }
        /** @var array{1: int} $decoded */
        $decoded = unpack('N', substr($payload, $offset, 4));
        $dependency = $decoded[1] & 0x7FFF_FFFF;
        if ($dependency === $frame->streamId) {
            throw new StreamError($frame->streamId, ErrorCode::PROTOCOL_ERROR, 'HTTP/2 stream cannot depend on itself.');
        }

        return $offset + 5;
    }

    private function createStream(int $id, ?int $contentLength): Http2Stream
    {
        $body = new StreamingRequestBody(
            $this->limits->bodyLowWatermarkBytes,
            $this->limits->bodyHighWatermarkBytes,
            $this->limits->maxPendingBodyBytesPerStream,
            static function (): void {},
            fn(int $bytes) => $this->creditBodyBytes($id, $bytes),
        );
        $stream = new Http2Stream($id, $body, $this->peerSettings->initialWindowSize, $this->limits->initialReceiveWindow());
        $stream->declaredContentLength = $contentLength;

        return $stream;
    }

    private function creditBodyBytes(int $streamId, int $bytes): void
    {
        if ($bytes > 0) {
            $this->creditReceive($this->stream($streamId), $bytes);
        }
    }

    private function creditReceive(?Http2Stream $stream, int $bytes): void
    {
        foreach ($this->flow->creditReceive($stream, $bytes) as $frame) {
            $this->output->sendControl($frame);
        }
    }

    /** @return array{0: string, 1: int, 2: int} */
    private function dataPayload(Frame $frame): array
    {
        $length = strlen($frame->payload);
        if (!$frame->hasFlag(0x8)) {
            return [$frame->payload, $length, 0];
        }
        if ($length === 0) {
            throw new ConnectionError(ErrorCode::PROTOCOL_ERROR, 'Padded HTTP/2 DATA frame is missing pad length.');
        }
        $padding = ord($frame->payload[0]);
        if ($padding > $length - 1) {
            throw new ConnectionError(ErrorCode::PROTOCOL_ERROR, 'HTTP/2 DATA padding exceeds payload length.');
        }
        $dataBytes = $length - 1 - $padding;

        return [substr($frame->payload, 1, $dataBytes), $length, 1 + $padding];
    }

    private function dispatch(Http2Stream $stream, ValidatedRequestHead $head, \Infocyph\Runwire\Http\Http2\Http2ResponseWriter $writer): void
    {
        $request = new HttpRequest(
            $head->method,
            $head->target,
            ProtocolVersion::HTTP_2,
            $head->headers,
            $stream->body,
            $this->connection->peerAddress(),
            $this->connection->localAddress(),
            $this->connection->isEncrypted(),
        );
        $stream->dispatching = true;

        try {
            ($this->handler)($request, $writer);
        } catch (Throwable) {
            $stream->dispatching = false;
            ($this->streamFailure)(new StreamError($stream->id, ErrorCode::INTERNAL_ERROR, 'Application handler failed.'));

            return;
        }
        $stream->dispatching = false;
    }

    private function finishRemote(Http2Stream $stream, ?Headers $trailers = null): void
    {
        if ($stream->declaredContentLength !== null && $stream->receivedBodyBytes !== $stream->declaredContentLength) {
            throw new StreamError($stream->id, ErrorCode::PROTOCOL_ERROR, 'HTTP/2 request body length does not match Content-Length.');
        }
        if (!$stream->body->cancelled()) {
            $stream->body->finish($trailers);
        }
        $stream->remoteEnd();
        $this->touch($stream);
        $this->cleanupIfClosed($stream);
    }

    private function handleDataForMissingStream(Frame $frame): void
    {
        if ($frame->streamId > $this->lastClientStreamId) {
            throw new ConnectionError(ErrorCode::PROTOCOL_ERROR, 'DATA received for an idle stream.');
        }
        $this->flow->consumeConnectionReceive(strlen($frame->payload));

        throw new StreamError($frame->streamId, ErrorCode::STREAM_CLOSED, 'DATA received for a closed stream.');
    }

    private function handleResponseEnd(int $streamId): void
    {
        $stream = $this->stream($streamId);
        if ($stream === null) {
            return;
        }
        if ($stream->remoteOpen()) {
            $stream->discardInbound = true;
            $stream->body->cancel();
        }
        $this->cleanupIfClosed($stream);
    }

    private function headersFragment(Frame $frame): string
    {
        $payload = $frame->payload;
        $length = strlen($payload);
        $offset = $frame->hasFlag(0x8) ? 1 : 0;
        $padding = $offset === 1 && $length > 0 ? ord($payload[0]) : 0;
        if ($offset === 1 && $length === 0) {
            throw new ConnectionError(ErrorCode::PROTOCOL_ERROR, 'Padded HTTP/2 HEADERS frame is missing pad length.');
        }
        if ($frame->hasFlag(0x20)) {
            $offset = $this->consumePriorityFields($frame, $payload, $length, $offset);
        }
        if ($padding > $length - $offset) {
            throw new ConnectionError(ErrorCode::PROTOCOL_ERROR, 'HTTP/2 HEADERS padding exceeds payload length.');
        }

        return substr($payload, $offset, $length - $offset - $padding);
    }

    /** @param list<array{0: string, 1: string}> $fields */
    private function openRequest(PendingHeaderBlock $pending, array $fields): void
    {
        $head = $this->validatedHead($pending, $fields);
        if (!$this->admit($pending, $head->contentLength)) {
            return;
        }

        $stream = $this->createStream($pending->streamId, $head->contentLength);
        $stream->open($pending->endStream);
        $this->streams[$stream->id] = $stream;
        $this->touch($stream);
        $writer = $this->output->writer($stream, $head->method, fn() => $this->handleResponseEnd($stream->id));
        $stream->writer = $writer;
        $this->dispatch($stream, $head, $writer);

        if ($pending->endStream && $this->stream($stream->id) !== null) {
            $this->finishRemote($stream);
        }
    }

    private function remove(Http2Stream $stream): void
    {
        $this->cancelTimer($stream->idleTimer);
        unset($this->streams[$stream->id]);
        ($this->streamRemoved)();
    }

    /** @param list<array{0: string, 1: string}> $fields */
    private function validatedHead(PendingHeaderBlock $pending, array $fields): ValidatedRequestHead
    {
        if (($pending->streamId & 1) === 0 || $pending->streamId <= $this->lastClientStreamId) {
            throw new ConnectionError(ErrorCode::PROTOCOL_ERROR, 'Client-initiated HTTP/2 stream IDs must be odd and strictly increasing.');
        }

        try {
            return $this->validator->request($fields);
        } catch (HeaderValidationException $error) {
            throw new StreamError($pending->streamId, ErrorCode::PROTOCOL_ERROR, $error->getMessage());
        }
    }

    private function validateHeadersStream(Frame $frame): void
    {
        if ($frame->streamId === 0) {
            throw new ConnectionError(ErrorCode::PROTOCOL_ERROR, 'HTTP/2 HEADERS requires a non-zero stream ID.');
        }
        $existing = $this->stream($frame->streamId);
        if ($existing === null && $frame->streamId <= $this->lastClientStreamId) {
            throw new StreamError($frame->streamId, ErrorCode::STREAM_CLOSED, 'HEADERS received for a closed stream.');
        }
        if ($existing !== null && !$existing->remoteOpen()) {
            throw new StreamError($frame->streamId, ErrorCode::STREAM_CLOSED, 'HEADERS received after remote stream closure.');
        }
    }
}
