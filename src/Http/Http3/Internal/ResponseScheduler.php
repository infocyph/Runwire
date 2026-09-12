<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Http3\Internal;

use Closure;
use Infocyph\Runwire\Http\Http3\Enum\FrameType;
use Infocyph\Runwire\Http\Http3\Frame;
use Infocyph\Runwire\Http\Http3\FrameWriter;
use Infocyph\Runwire\Http\Http3\Http3Limits;
use Infocyph\Runwire\Http\Http3\Http3ResponseWriter;
use Infocyph\Runwire\Http\Http3\Qpack\Encoder;
use Infocyph\Runwire\Network\Enum\WriteState;
use Infocyph\Runwire\Network\Internal\ByteQueue;
use Infocyph\Runwire\Network\WriteResult;
use LogicException;

final class ResponseScheduler
{
    private const int TRANSPORT_CHUNK_BYTES = 16_384;

    private readonly Encoder $fallbackEncoder;

    private readonly ByteQueue $qpackEncoderQueue;

    /** @var array<int, true> */
    private array $flushQueue = [];

    private int $pendingResponseBytes = 0;

    private bool $qpackTransportPressured = false;

    /** @var array<int, ResponseStream> */
    private array $streams = [];

    public function __construct(
        private readonly ConnectionState $state,
        private readonly Http3Limits $limits,
        private readonly Http3TransportInterface $transport,
    ) {
        $this->fallbackEncoder = new Encoder(0, 0, $limits->maxFieldSectionBytes, 0);
        $this->qpackEncoderQueue = new ByteQueue();
    }

    public function discardStream(int $streamId): void
    {
        $stream = $this->streams[$streamId] ?? null;
        if ($stream === null) {
            return;
        }

        $this->pendingResponseBytes = max(0, $this->pendingResponseBytes - $stream->outbound->bytes());
        $stream->outbound->clear();
        $stream->drainCallback = null;
        $stream->ended = true;
        $stream->endPending = false;
        $stream->transportPressured = false;
        $stream->writePressured = false;
        unset($this->flushQueue[$streamId], $this->streams[$streamId]);
    }

    public function flush(): void
    {
        $writes = 0;
        $stalled = 0;
        while ($writes < $this->limits->maxWritesPerFlush) {
            $qpackProgress = $this->flushQpack();
            if ($qpackProgress) {
                ++$writes;
            }
            if ($writes >= $this->limits->maxWritesPerFlush) {
                break;
            }

            $stream = $this->nextStream();
            if ($stream === null) {
                if (!$qpackProgress) {
                    break;
                }

                continue;
            }

            $streamProgress = $this->flushResponseStream($stream);
            if ($streamProgress) {
                ++$writes;
                $stalled = 0;

                continue;
            }
            if ($qpackProgress) {
                $stalled = 0;

                continue;
            }
            if (++$stalled >= count($this->flushQueue) + 1) {
                break;
            }
        }

        $this->relieveStreams();
    }

    public function qpackPending(): bool
    {
        return !$this->qpackEncoderQueue->isEmpty();
    }

    public function responsePending(int $streamId): bool
    {
        $stream = $this->streams[$streamId] ?? null;

        return $stream !== null && (!$stream->outbound->isEmpty() || $stream->endPending);
    }

    /** @param callable(): void $onEnd */
    public function writer(int $streamId, string $method, callable $onEnd): Http3ResponseWriter
    {
        if (isset($this->streams[$streamId])) {
            throw new LogicException('HTTP/3 response writer already exists for the request stream.');
        }

        $stream = $this->streams[$streamId] = new ResponseStream($streamId);

        return new Http3ResponseWriter(
            $method,
            fn(int $status, array $headers) => $this->queueHeaders($stream, $status, $headers),
            fn(string $data, bool $end) => $this->queueData($stream, $data, $end),
            function (Closure $callback) use ($stream): void {
                $stream->drainCallback = $callback;
            },
            $onEnd,
        );
    }

    private function appendDataFrames(ResponseStream $stream, string $data): void
    {
        $offset = 0;
        $length = strlen($data);
        while ($offset < $length) {
            $payload = substr($data, $offset, $this->limits->maxResponseFramePayloadBytes);
            $wire = FrameWriter::encode(new Frame(FrameType::DATA->value, $payload));
            $stream->outbound->append($wire);
            $this->pendingResponseBytes += strlen($wire);
            $offset += strlen($payload);
        }
    }

    /** @param list<array{0: string, 1: string}> $headers */
    private function appendHeader(
        ResponseStream $stream,
        Encoder $encoder,
        array $headers,
        int $headerReserve,
        int $instructionReserve,
    ): void {
        $section = $encoder->encode($headers, $stream->id);
        $wire = FrameWriter::encode(new Frame(FrameType::HEADERS->value, $section->block));
        $instructions = $encoder->takeEncoderInstructions();
        if (strlen($wire) > $headerReserve || strlen($instructions) > $instructionReserve) {
            throw new LogicException('HTTP/3 QPACK reservation underestimated encoded response bytes.');
        }

        $this->qpackEncoderQueue->append($instructions);
        $stream->outbound->append($wire);
        $this->pendingResponseBytes += strlen($wire);
        $this->flushQueue[$stream->id] = true;
    }

    private function canReserveHeader(
        ResponseStream $stream,
        int $headerReserve,
        int $instructionReserve,
    ): bool {
        if ($headerReserve > $this->limits->maxPendingResponseBytesPerStream - $stream->outbound->bytes()) {
            return false;
        }
        if ($instructionReserve > $this->limits->maxQpackEncoderQueueBytes - $this->qpackEncoderQueue->bytes()) {
            return false;
        }

        return $headerReserve + $instructionReserve
            <= $this->limits->maxPendingResponseBytesPerConnection - $this->connectionBufferedBytes();
    }

    private function canReserveResponse(ResponseStream $stream, int $bytes): bool
    {
        if ($bytes > $this->limits->maxPendingResponseBytesPerStream - $stream->outbound->bytes()) {
            return false;
        }

        return $bytes <= $this->limits->maxPendingResponseBytesPerConnection - $this->connectionBufferedBytes();
    }

    private function closedResult(): WriteResult
    {
        return new WriteResult(WriteState::CLOSED, 0);
    }

    private function connectionBufferedBytes(): int
    {
        return $this->pendingResponseBytes + $this->qpackEncoderQueue->bytes();
    }

    private function currentEncoder(): Encoder
    {
        return $this->state->responseEncoder() ?? $this->fallbackEncoder;
    }

    private function dataWireBudget(int $bytes): int
    {
        if ($bytes === 0) {
            return 0;
        }

        $frames = intdiv($bytes + $this->limits->maxResponseFramePayloadBytes - 1, $this->limits->maxResponseFramePayloadBytes);

        return $bytes + ($frames * 16);
    }

    /** @param list<array{0: string, 1: string}> $headers */
    private function fieldSectionBytes(array $headers): ?int
    {
        if (count($headers) > $this->limits->maxHeaderFields) {
            return null;
        }

        $bytes = 0;
        $limit = $this->headerLimit();
        foreach ($headers as [$name, $value]) {
            $addition = 32 + strlen($name) + strlen($value);
            if ($addition > $limit - $bytes) {
                return null;
            }
            $bytes += $addition;
        }

        return $bytes;
    }

    private function flushQpack(): bool
    {
        if ($this->qpackEncoderQueue->isEmpty()) {
            $this->qpackTransportPressured = false;

            return false;
        }

        $chunk = $this->qpackEncoderQueue->front(min(self::TRANSPORT_CHUNK_BYTES, $this->qpackEncoderQueue->bytes()));
        $written = $this->transport->writeQpackEncoder($chunk);
        $this->validateWritten($written, strlen($chunk));
        if ($written === 0) {
            $this->qpackTransportPressured = true;

            return false;
        }

        $this->qpackEncoderQueue->discard($written);
        $this->qpackTransportPressured = $written < strlen($chunk);
        if ($this->qpackEncoderQueue->isEmpty()) {
            $this->qpackTransportPressured = false;
        }

        return true;
    }

    private function flushRequest(ResponseStream $stream): bool
    {
        if ($stream->outbound->isEmpty()) {
            if (!$stream->endPending) {
                return false;
            }
            $this->transport->finishRequestStream($stream->id);
            $stream->endPending = false;
            $stream->ended = true;
            $stream->transportPressured = false;

            return true;
        }

        $chunk = $stream->outbound->front(min(self::TRANSPORT_CHUNK_BYTES, $stream->outbound->bytes()));
        $written = $this->transport->writeRequestStream($stream->id, $chunk);
        $this->validateWritten($written, strlen($chunk));
        if ($written === 0) {
            $stream->transportPressured = true;

            return false;
        }

        $stream->outbound->discard($written);
        $this->pendingResponseBytes -= $written;
        $stream->transportPressured = $written < strlen($chunk);
        if ($stream->outbound->isEmpty()) {
            $stream->transportPressured = false;
            if ($stream->endPending) {
                $this->transport->finishRequestStream($stream->id);
                $stream->endPending = false;
                $stream->ended = true;
            }
        }

        return true;
    }

    private function flushResponseStream(ResponseStream $stream): bool
    {
        $progress = $this->flushRequest($stream);
        if (!$stream->ended && (!$stream->outbound->isEmpty() || $stream->endPending)) {
            $this->flushQueue[$stream->id] = true;
        }

        return $progress;
    }

    private function headerLimit(): int
    {
        return min(
            $this->limits->maxFieldSectionBytes,
            $this->state->peerSettings()?->maxFieldSectionSize() ?? $this->limits->maxFieldSectionBytes,
        );
    }

    private function nextStream(): ?ResponseStream
    {
        $streamId = array_key_first($this->flushQueue);
        if ($streamId === null) {
            return null;
        }
        unset($this->flushQueue[$streamId]);

        return $this->streams[$streamId] ?? null;
    }

    private function queueData(ResponseStream $stream, string $data, bool $end): WriteResult
    {
        if ($stream->ended) {
            return $this->closedResult();
        }

        $budget = $this->dataWireBudget(strlen($data));
        if (!$this->canReserveResponse($stream, $budget)) {
            return $this->rejectedResult($stream);
        }

        if ($data !== '') {
            $this->appendDataFrames($stream, $data);
        }
        $stream->endPending = $stream->endPending || $end;
        if ($data !== '' || $end) {
            $this->flushQueue[$stream->id] = true;
        }
        $this->flush();

        return $this->responseResult($stream);
    }

    /** @param list<array{0: string, 1: string}> $headers */
    private function queueHeaders(ResponseStream $stream, int $status, array $headers): WriteResult
    {
        if ($stream->ended) {
            return $this->closedResult();
        }
        if (($headers[0] ?? null) !== [':status', (string) $status]) {
            throw new LogicException('HTTP/3 response status pseudo-header is inconsistent.');
        }

        $fieldBytes = $this->fieldSectionBytes($headers);
        if ($fieldBytes === null) {
            return $this->rejectedResult($stream);
        }

        $encoder = $this->currentEncoder();
        $headerReserve = $fieldBytes + 32;
        $instructionReserve = $encoder === $this->fallbackEncoder ? 0 : $fieldBytes + 64;
        if (!$this->canReserveHeader($stream, $headerReserve, $instructionReserve)) {
            return $this->rejectedResult($stream);
        }

        $this->appendHeader($stream, $encoder, $headers, $headerReserve, $instructionReserve);
        $this->flush();

        return $this->responseResult($stream);
    }

    private function rejectedResult(ResponseStream $stream): WriteResult
    {
        return new WriteResult(WriteState::REJECTED_LIMIT, $stream->outbound->bytes());
    }

    private function relieveStreams(): void
    {
        foreach ($this->streams as $stream) {
            if (!$stream->writePressured
                || $stream->transportPressured
                || $this->qpackTransportPressured
                || $stream->outbound->bytes() > $this->limits->responseLowWatermarkBytes) {
                continue;
            }

            $stream->writePressured = false;
            ($stream->drainCallback)?->__invoke();
        }
    }

    private function responseResult(ResponseStream $stream): WriteResult
    {
        $pressured = $stream->outbound->bytes() >= $this->limits->responseHighWatermarkBytes
            || $stream->transportPressured
            || $this->qpackTransportPressured;
        if ($pressured) {
            $stream->writePressured = true;
        }

        return new WriteResult(
            $pressured ? WriteState::PRESSURED : WriteState::ACCEPTED,
            $stream->outbound->bytes(),
        );
    }

    private function validateWritten(int $written, int $offered): void
    {
        if ($written < 0 || $written > $offered) {
            throw new LogicException('HTTP/3 transport returned an invalid accepted byte count.');
        }
    }
}
