<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Http2\Internal;

use Closure;
use Infocyph\Runwire\Http\Http2\Frame;
use Infocyph\Runwire\Http\Http2\FrameType;
use Infocyph\Runwire\Http\Http2\FrameWriter;
use Infocyph\Runwire\Http\Http2\Hpack\Encoder;
use Infocyph\Runwire\Http\Http2\Http2Limits;
use Infocyph\Runwire\Http\Http2\Http2ResponseWriter;
use Infocyph\Runwire\Http\Http2\PeerSettings;
use Infocyph\Runwire\Network\CloseReason;
use Infocyph\Runwire\Network\Connection;
use Infocyph\Runwire\Network\Internal\ByteQueue;
use Infocyph\Runwire\Network\WriteResult;
use Infocyph\Runwire\Network\WriteState;

final class ResponseScheduler
{
    private const int OUTBOUND_FRAME_SIZE = 16_384;

    private const int WIRE_CHUNK_BYTES = self::OUTBOUND_FRAME_SIZE + 9;

    /** @var Closure(Http2Stream): void */
    private readonly Closure $activityCallback;

    /** @var Closure(Http2Stream): void */
    private readonly Closure $cleanupClosed;

    /** @var Closure(): void */
    private readonly Closure $readyCallback;

    /** @var Closure(int): ?Http2Stream */
    private readonly Closure $streamLookup;

    private readonly ByteQueue $wireQueue;

    /** @var array<int, true> */
    private array $flushQueue = [];

    private int $pendingStreamBytes = 0;

    /** @var array<int, true> */
    private array $pressuredStreams = [];

    private bool $transportPressured = false;

    /**
     * @param callable(int): ?Http2Stream $streamLookup
     * @param callable(Http2Stream): void $cleanupClosed
     * @param callable(): void $readyCallback
     * @param callable(Http2Stream): void $activityCallback
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly Http2Limits $limits,
        private readonly PeerSettings $peerSettings,
        private readonly Encoder $encoder,
        private readonly FlowController $flow,
        callable $streamLookup,
        callable $cleanupClosed,
        callable $readyCallback,
        callable $activityCallback,
    ) {
        /** @var Closure(int): ?Http2Stream $lookupClosure */
        $lookupClosure = Closure::fromCallable($streamLookup);
        $this->streamLookup = $lookupClosure;
        /** @var Closure(Http2Stream): void $cleanupClosure */
        $cleanupClosure = Closure::fromCallable($cleanupClosed);
        $this->cleanupClosed = $cleanupClosure;
        /** @var Closure(): void $readyClosure */
        $readyClosure = Closure::fromCallable($readyCallback);
        $this->readyCallback = $readyClosure;
        /** @var Closure(Http2Stream): void $activityClosure */
        $activityClosure = Closure::fromCallable($activityCallback);
        $this->activityCallback = $activityClosure;
        $this->wireQueue = new ByteQueue();
        $connection->onDrain(fn() => $this->handleTransportDrain());
    }

    public function cleanup(): void
    {
        $this->wireQueue->clear();
        $this->flushQueue = [];
        $this->pressuredStreams = [];
        $this->pendingStreamBytes = 0;
        $this->transportPressured = false;
    }

    public function discardStream(Http2Stream $stream): void
    {
        $bytes = $stream->outbound->bytes();
        $this->pendingStreamBytes = max(0, $this->pendingStreamBytes - $bytes);
        unset($this->flushQueue[$stream->id], $this->pressuredStreams[$stream->id]);
    }

    public function flush(): void
    {
        if ($this->blocked() || $this->flushQueue === []) {
            return;
        }

        $frames = 0;
        $stalled = 0;
        while ($this->canFlush($frames)) {
            $stream = $this->nextStream();
            if ($stream === null) {
                continue;
            }

            $progress = $this->flushOne($stream);
            if (!$stream->outbound->isEmpty() || $stream->endPending) {
                $this->flushQueue[$stream->id] = true;
            }
            if ($progress) {
                ++$frames;
                $stalled = 0;

                continue;
            }
            if (++$stalled >= count($this->flushQueue) + 1) {
                break;
            }
        }

        $this->relieveStreams();
    }

    public function sendControl(Frame $frame): WriteResult
    {
        return $this->sendFrame($frame);
    }

    public function transportPressured(): bool
    {
        return $this->transportPressured;
    }

    public function wireIdle(): bool
    {
        return $this->wireQueue->isEmpty();
    }

    public function writer(Http2Stream $stream, string $method, callable $onEnd): Http2ResponseWriter
    {
        return new Http2ResponseWriter(
            $method,
            fn(int $_status, array $headers) => $this->sendHeaders($stream, $headers),
            fn(string $data, bool $end) => $this->queueData($stream, $data, $end),
            function (Closure $callback) use ($stream): void {
                $stream->drainCallback = $callback;
            },
            $onEnd,
        );
    }

    private function blocked(): bool
    {
        return $this->transportPressured || !$this->wireQueue->isEmpty();
    }

    private function canFlush(int $frames): bool
    {
        return $this->flushQueue !== []
            && $frames < $this->limits->maxFramesPerFlush
            && !$this->blocked();
    }

    private function closedResult(): WriteResult
    {
        return new WriteResult(WriteState::CLOSED, 0);
    }

    private function fitsResponseLimits(Http2Stream $stream, int $bytes): bool
    {
        if ($bytes > $this->limits->maxPendingResponseBytesPerStream - $stream->outbound->bytes()) {
            return false;
        }

        return $bytes <= $this->limits->maxPendingResponseBytesPerConnection
            - $this->pendingStreamBytes
            - $this->wireQueue->bytes();
    }

    private function flushEnd(Http2Stream $stream): bool
    {
        if (!$stream->endPending) {
            return false;
        }
        $result = $this->sendFrame(new Frame(FrameType::DATA->value, 0x1, $stream->id));
        if (!$result->accepted()) {
            return false;
        }
        $stream->endPending = false;
        $stream->localEnd();
        ($this->cleanupClosed)($stream);

        return true;
    }

    private function flushOne(Http2Stream $stream): bool
    {
        if ($stream->outbound->isEmpty()) {
            return $this->flushEnd($stream);
        }
        $available = min(
            $this->flow->availableSend($stream),
            $this->peerSettings->maxFrameSize,
            self::OUTBOUND_FRAME_SIZE,
        );
        if ($available <= 0) {
            return false;
        }

        $chunk = $stream->outbound->front($available);
        $end = $stream->endPending && strlen($chunk) === $stream->outbound->bytes();
        $result = $this->sendFrame(new Frame(FrameType::DATA->value, $end ? 0x1 : 0, $stream->id, $chunk));
        if (!$result->accepted()) {
            return false;
        }

        $bytes = strlen($chunk);
        $stream->outbound->discard($bytes);
        $this->pendingStreamBytes -= $bytes;
        $this->flow->consumeSend($stream, $bytes);
        if ($end) {
            $stream->endPending = false;
            $stream->localEnd();
            ($this->cleanupClosed)($stream);
        }

        return true;
    }

    private function flushWireChunk(): bool
    {
        $chunk = $this->wireQueue->front(min(self::WIRE_CHUNK_BYTES, $this->wireQueue->bytes()));
        $result = $this->connection->write($chunk);
        if (!$result->accepted()) {
            $this->transportPressured = true;

            return false;
        }
        $this->wireQueue->discard(strlen($chunk));
        if ($result->pressured()) {
            $this->transportPressured = true;

            return false;
        }

        return true;
    }

    private function handleTransportDrain(): void
    {
        $this->transportPressured = false;
        while (!$this->wireQueue->isEmpty()) {
            if (!$this->flushWireChunk()) {
                return;
            }
        }
        $this->flush();
        $this->relieveStreams();
        ($this->readyCallback)();
    }

    /**
     * @param list<array{0: string, 1: string}> $headers
     * @return list<Frame>|null
     */
    private function headerFrames(Http2Stream $stream, array $headers): ?array
    {
        $bytes = 0;
        foreach ($headers as [$name, $value]) {
            $bytes += 32 + strlen($name) + strlen($value);
        }
        $peerLimit = $this->peerSettings->maxHeaderListSize ?? PHP_INT_MAX;
        if ($bytes > min($peerLimit, $this->limits->maxHeaderListBytes)) {
            return null;
        }

        $block = $this->encoder->encode($headers);
        if (strlen($block) > $this->limits->maxHeaderBlockBytes) {
            return null;
        }

        $frameSize = max(1, min($this->peerSettings->maxFrameSize, self::OUTBOUND_FRAME_SIZE));
        $chunks = str_split($block, $frameSize) ?: [''];
        $frames = [];
        foreach ($chunks as $index => $chunk) {
            $last = $index === count($chunks) - 1;
            $type = $index === 0 ? FrameType::HEADERS : FrameType::CONTINUATION;
            $frames[] = new Frame($type->value, $last ? 0x4 : 0, $stream->id, $chunk);
        }

        return $frames;
    }

    private function nextStream(): ?Http2Stream
    {
        $streamId = array_key_first($this->flushQueue);
        if ($streamId === null) {
            return null;
        }
        unset($this->flushQueue[$streamId]);
        $stream = ($this->streamLookup)($streamId);

        return $stream instanceof Http2Stream && $stream->localOpen() ? $stream : null;
    }

    private function queueData(Http2Stream $stream, string $data, bool $end): WriteResult
    {
        if (!$stream->localOpen()) {
            return $this->closedResult();
        }
        if (!$this->fitsResponseLimits($stream, strlen($data))) {
            return new WriteResult(WriteState::REJECTED_LIMIT, $stream->outbound->bytes());
        }

        if ($data !== '') {
            $stream->outbound->append($data);
            $this->pendingStreamBytes += strlen($data);
        }
        $stream->endPending = $stream->endPending || $end;
        if ($data !== '' || $end) {
            $this->flushQueue[$stream->id] = true;
        }
        $this->flush();
        ($this->activityCallback)($stream);

        return $this->streamWriteResult($stream);
    }

    private function queueWire(string $wire): WriteResult
    {
        if (strlen($wire) > $this->limits->maxWireQueueBytes - $this->wireQueue->bytes()) {
            $this->connection->abort(CloseReason::WRITE_ERROR);

            return $this->closedResult();
        }
        $this->wireQueue->append($wire);
        $this->transportPressured = true;

        return new WriteResult(WriteState::PRESSURED, $this->wireQueue->bytes());
    }

    private function relieveStreams(): void
    {
        if ($this->blocked()) {
            return;
        }
        foreach (array_keys($this->pressuredStreams) as $streamId) {
            $stream = ($this->streamLookup)($streamId);
            if (!$stream instanceof Http2Stream) {
                unset($this->pressuredStreams[$streamId]);

                continue;
            }
            if ($stream->outbound->bytes() > $this->limits->responseLowWatermarkBytes) {
                continue;
            }
            $stream->writePressured = false;
            unset($this->pressuredStreams[$streamId]);
            ($stream->drainCallback)?->__invoke();
        }
    }

    private function sendFrame(Frame $frame): WriteResult
    {
        $wire = FrameWriter::encode($frame);
        if (!$this->wireQueue->isEmpty()) {
            return $this->queueWire($wire);
        }
        $result = $this->connection->write($wire);
        if ($result->state === WriteState::REJECTED_LIMIT) {
            return $this->queueWire($wire);
        }
        if ($result->pressured()) {
            $this->transportPressured = true;
        }

        return $result;
    }

    /** @param list<array{0: string, 1: string}> $headers */
    private function sendHeaders(Http2Stream $stream, array $headers): WriteResult
    {
        if (!$stream->localOpen()) {
            return $this->closedResult();
        }
        $frames = $this->headerFrames($stream, $headers);
        if ($frames === null) {
            return new WriteResult(WriteState::REJECTED_LIMIT, $stream->outbound->bytes());
        }

        $pressured = false;
        foreach ($frames as $frame) {
            $result = $this->sendFrame($frame);
            if (!$result->accepted()) {
                return $result;
            }
            $pressured = $pressured || $result->pressured();
        }

        ($this->activityCallback)($stream);

        return new WriteResult(
            $pressured ? WriteState::PRESSURED : WriteState::ACCEPTED,
            $stream->outbound->bytes(),
        );
    }

    private function streamWriteResult(Http2Stream $stream): WriteResult
    {
        $pressured = $stream->outbound->bytes() >= $this->limits->responseHighWatermarkBytes
            || $this->transportPressured
            || !$this->wireQueue->isEmpty();
        if ($pressured) {
            $stream->writePressured = true;
            $this->pressuredStreams[$stream->id] = true;
        }

        return new WriteResult(
            $pressured ? WriteState::PRESSURED : WriteState::ACCEPTED,
            $stream->outbound->bytes(),
        );
    }
}
