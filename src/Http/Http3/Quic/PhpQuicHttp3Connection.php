<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Http3\Quic;

use Infocyph\Runwire\Http\Http3\ErrorCode;
use Infocyph\Runwire\Http\Http3\Frame;
use Infocyph\Runwire\Http\Http3\FrameType;
use Infocyph\Runwire\Http\Http3\FrameWriter;
use Infocyph\Runwire\Http\Http3\Http3Exception;
use Infocyph\Runwire\Http\Http3\Http3Limits;
use Infocyph\Runwire\Http\Http3\Http3Session;
use Infocyph\Runwire\Http\Http3\Internal\ConnectionState;
use Infocyph\Runwire\Http\Http3\Internal\ResponseScheduler;
use Infocyph\Runwire\Http\Http3\VarIntCodec;
use Infocyph\Runwire\Http\HttpRequest;
use Infocyph\Runwire\Http\ResponseWriterInterface;

final class PhpQuicHttp3Connection
{
    private readonly PhpQuicStream $controlStream;

    private readonly PhpQuicStream $qpackDecoderStream;

    private readonly ResponseScheduler $scheduler;

    private readonly Http3Session $session;

    private readonly ConnectionState $state;

    private readonly PhpQuicTransport $transport;

    /** @var array<int, true> */
    private array $applicationFinishedResponses = [];

    private bool $closed = false;

    private string $controlPending;

    private ?int $drainBoundary = null;

    /** @var array<int, true> */
    private array $peerFinishedRequests = [];

    /** @var array<int, PhpQuicStream> */
    private array $peerStreams = [];

    private string $qpackDecoderPending;

    /** @var array<int, true> */
    private array $requestStreams = [];

    /** @param callable(HttpRequest, ResponseWriterInterface): void $handler */
    public function __construct(
        private readonly PhpQuicConnection $connection,
        callable $handler,
        private readonly Http3Limits $limits = new Http3Limits(),
        ?string $peerAddress = null,
        ?string $localAddress = null,
    ) {
        $this->connection->setNonBlocking();
        if ($this->connection->negotiatedAlpn() !== 'h3') {
            throw new Http3Exception(ErrorCode::GENERAL_PROTOCOL_ERROR, 'QUIC connection did not negotiate the h3 ALPN protocol.');
        }

        $this->state = new ConnectionState($limits);
        $this->controlStream = $this->openCriticalStream('control');
        $qpackEncoderStream = $this->openCriticalStream('QPACK encoder');
        $this->qpackDecoderStream = $this->openCriticalStream('QPACK decoder');
        $this->controlPending = $this->state->localControlPreamble();
        $this->qpackDecoderPending = $this->state->localQpackDecoderPreamble();
        $this->transport = new PhpQuicTransport($qpackEncoderStream, $this->state->localQpackEncoderPreamble());
        $this->scheduler = new ResponseScheduler($this->state, $limits, $this->transport);
        $this->session = new Http3Session(
            $this->state,
            $handler,
            fn(int $streamId, string $method): ResponseWriterInterface => $this->scheduler->writer(
                $streamId,
                $method,
                function () use ($streamId): void {
                    $this->applicationFinishedResponses[$streamId] = true;
                },
            ),
            $peerAddress,
            $localAddress,
        );

        $this->flush();
    }

    public function activeRequestStreams(): int
    {
        return count($this->requestStreams);
    }

    public function beginDrain(): void
    {
        if ($this->closed || $this->drainBoundary !== null) {
            return;
        }

        $this->drainBoundary = $this->nextRequestStreamId();
        $this->controlPending .= FrameWriter::encode(new Frame(
            FrameType::GOAWAY->value,
            VarIntCodec::encode($this->drainBoundary),
        ));
        $this->flush();
    }

    public function closed(): bool
    {
        return $this->closed;
    }

    public function connection(): PhpQuicConnection
    {
        return $this->connection;
    }

    public function draining(): bool
    {
        return $this->drainBoundary !== null;
    }

    /** @param array<int, int> $ready */
    public function handleReady(array $ready, PhpQuicEventMasks $events): void
    {
        if ($this->closed) {
            return;
        }

        try {
            if ($this->connectionErrored($ready, $events)) {
                $this->closeObservedConnection();

                return;
            }
            if ($this->objectReady($ready, $this->connection->object(), $events->acceptStream)) {
                $this->acceptAvailableStreams();
            }
            $this->assertCriticalStreamsOpen($ready, $events);
            $this->drainReadyStreams($ready, $events);
            $this->queueDecoderInstructions();
            $this->flush();
            $this->cleanupFinishedRequests();
        } catch (Http3Exception $exception) {
            $this->abort($exception);

            throw $exception;
        }
    }

    public function peerStream(int $streamId): ?PhpQuicStream
    {
        return $this->peerStreams[$streamId] ?? null;
    }

    /** @return array<int, array{0: object, 1: int}> */
    public function pollItems(PhpQuicEventMasks $events): array
    {
        if ($this->closed) {
            return [];
        }

        $items = [];
        $this->putPollItem($items, $this->connection->object(), $events->acceptStream | $events->error);
        $this->putPollItem($items, $this->controlStream->object(), $this->criticalEvents($this->controlPending !== '', $events));
        $this->putPollItem($items, $this->qpackDecoderStream->object(), $this->criticalEvents($this->qpackDecoderPending !== '', $events));
        $this->putPollItem(
            $items,
            $this->transport->qpackEncoderObject(),
            $this->criticalEvents($this->transport->qpackEncoderPreamblePending() || $this->scheduler->qpackPending(), $events),
        );

        foreach ($this->peerStreams as $streamId => $stream) {
            $mask = $events->error;
            if (!isset($this->requestStreams[$streamId]) || !$this->requestPressured($streamId)) {
                $mask |= $events->read;
            }
            if (isset($this->requestStreams[$streamId]) && $this->scheduler->responsePending($streamId)) {
                $mask |= $events->write;
            }
            $this->putPollItem($items, $stream->object(), $mask);
        }

        return $items;
    }

    public function pump(): void
    {
        if ($this->closed) {
            return;
        }

        try {
            $this->acceptAvailableStreams();
            $this->flush();
            $this->drainReadableStreams();
            $this->queueDecoderInstructions();
            $this->flush();
            $this->cleanupFinishedRequests();
        } catch (Http3Exception $exception) {
            $this->abort($exception);

            throw $exception;
        }
    }

    private function abort(Http3Exception $exception): void
    {
        if ($this->closed) {
            return;
        }

        $this->connection->close($exception->errorCode->value, substr($exception->getMessage(), 0, 256), true);
        $this->closeObservedConnection();
    }

    private function acceptAvailableStreams(): void
    {
        for ($accepted = 0; $accepted < $this->limits->maxStreamsAcceptedPerPump; ++$accepted) {
            $stream = $this->connection->acceptStream();
            if ($stream === null) {
                return;
            }

            $this->registerPeerStream($stream);
        }
    }

    /** @param array<int, int> $ready */
    private function assertCriticalStreamsOpen(array $ready, PhpQuicEventMasks $events): void
    {
        foreach ([
            $this->controlStream->object(),
            $this->qpackDecoderStream->object(),
            $this->transport->qpackEncoderObject(),
        ] as $stream) {
            if ($this->objectReady($ready, $stream, $events->error)) {
                throw new Http3Exception(ErrorCode::CLOSED_CRITICAL_STREAM, 'A local HTTP/3 critical stream was closed.');
            }
        }
    }

    private function cancelRequestStream(int $streamId): void
    {
        $this->session->cancelRequestStream($streamId);
        $this->queueDecoderInstructions();
        $this->scheduler->discardStream($streamId);
        $this->transport->releaseRequestStream($streamId);
        unset(
            $this->applicationFinishedResponses[$streamId],
            $this->peerStreams[$streamId],
            $this->requestStreams[$streamId],
            $this->peerFinishedRequests[$streamId],
        );
    }

    private function cleanupFinishedRequests(): void
    {
        foreach (array_keys($this->peerFinishedRequests) as $streamId) {
            if (!isset($this->applicationFinishedResponses[$streamId]) || !$this->transport->responseFinished($streamId)) {
                continue;
            }

            $this->scheduler->discardStream($streamId);
            $this->transport->releaseRequestStream($streamId);
            $this->session->releaseRequestStream($streamId);
            unset(
                $this->applicationFinishedResponses[$streamId],
                $this->peerStreams[$streamId],
                $this->requestStreams[$streamId],
                $this->peerFinishedRequests[$streamId],
            );
        }
    }

    private function closeObservedConnection(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;
        foreach (array_keys($this->requestStreams) as $streamId) {
            $this->session->cancelRequestStream($streamId);
            $this->scheduler->discardStream($streamId);
            $this->transport->releaseRequestStream($streamId);
        }
        $this->applicationFinishedResponses = [];
        $this->peerStreams = [];
        $this->requestStreams = [];
        $this->peerFinishedRequests = [];
    }

    /** @param array<int, int> $ready */
    private function connectionErrored(array $ready, PhpQuicEventMasks $events): bool
    {
        return $this->objectReady($ready, $this->connection->object(), $events->error);
    }

    private function criticalEvents(bool $writePending, PhpQuicEventMasks $events): int
    {
        return $events->error | ($writePending ? $events->write : 0);
    }

    private function drainReadableStreams(): void
    {
        $reads = 0;
        $bytes = 0;
        foreach (array_keys($this->peerStreams) as $streamId) {
            if ($reads >= $this->limits->maxReadsPerPump || $bytes >= $this->limits->maxInboundBytesPerPump) {
                return;
            }

            [$streamReads, $streamBytes] = $this->drainStream(
                $streamId,
                $this->limits->maxReadsPerPump - $reads,
                $this->limits->maxInboundBytesPerPump - $bytes,
            );
            $reads += $streamReads;
            $bytes += $streamBytes;
        }
    }

    /** @param array<int, int> $ready */
    private function drainReadyStreams(array $ready, PhpQuicEventMasks $events): void
    {
        $reads = 0;
        $bytes = 0;
        foreach ($this->peerStreams as $streamId => $stream) {
            $mask = $ready[spl_object_id($stream->object())] ?? 0;
            if (($mask & ($events->read | $events->error)) === 0) {
                continue;
            }
            if (isset($this->requestStreams[$streamId]) && $stream->resetCode() !== null) {
                $this->cancelRequestStream($streamId);

                continue;
            }
            if ($reads >= $this->limits->maxReadsPerPump || $bytes >= $this->limits->maxInboundBytesPerPump) {
                return;
            }

            [$streamReads, $streamBytes] = $this->drainStream(
                $streamId,
                $this->limits->maxReadsPerPump - $reads,
                $this->limits->maxInboundBytesPerPump - $bytes,
            );
            $reads += $streamReads;
            $bytes += $streamBytes;
        }
    }

    /** @return array{0: int, 1: int} */
    private function drainStream(int $streamId, int $readBudget, int $byteBudget): array
    {
        $stream = $this->peerStreams[$streamId] ?? null;
        if ($stream === null || $this->requestPressured($streamId)) {
            return [0, 0];
        }

        $reads = 0;
        $bytes = 0;
        while ($reads < $readBudget && $bytes < $byteBudget) {
            $chunk = $stream->read(min($this->limits->streamReadChunkBytes, $byteBudget - $bytes));
            ++$reads;
            if ($chunk === null) {
                $this->finishPeerStream($streamId, $stream);

                break;
            }
            if ($chunk === '') {
                break;
            }

            $length = strlen($chunk);
            $bytes += $length;
            if (isset($this->requestStreams[$streamId])) {
                $this->session->pushRequestStream($streamId, $chunk);
            } else {
                $this->session->pushPeerUnidirectional($streamId, $chunk);
            }
            $this->queueDecoderInstructions();
            if ($this->requestPressured($streamId)) {
                break;
            }
        }

        return [$reads, $bytes];
    }

    private function finishPeerStream(int $streamId, PhpQuicStream $stream): void
    {
        if (isset($this->requestStreams[$streamId])) {
            if ($stream->resetCode() !== null) {
                $this->cancelRequestStream($streamId);

                return;
            }

            $this->session->finishRequestStream($streamId);
            $this->peerFinishedRequests[$streamId] = true;
            $this->cleanupFinishedRequests();

            return;
        }

        $this->session->finishPeerUnidirectional($streamId);
        unset($this->peerStreams[$streamId]);
    }

    private function flush(): void
    {
        $this->controlPending = $this->flushPending($this->controlStream, $this->controlPending);
        $this->qpackDecoderPending = $this->flushPending($this->qpackDecoderStream, $this->qpackDecoderPending);
        $this->transport->flushQpackEncoderPreamble();
        $this->scheduler->flush();
    }

    private function flushPending(PhpQuicStream $stream, string $pending): string
    {
        if ($pending === '') {
            return '';
        }

        $chunk = substr($pending, 0, $this->limits->streamReadChunkBytes);
        $written = $stream->write($chunk);

        return substr($pending, $written);
    }

    private function nextRequestStreamId(): int
    {
        if ($this->requestStreams === []) {
            return 0;
        }

        $highest = max(array_keys($this->requestStreams));
        if ($highest > VarIntCodec::MAX_VALUE - 4) {
            throw new Http3Exception(ErrorCode::ID_ERROR, 'HTTP/3 request stream id exceeds the GOAWAY range.');
        }

        return $highest + 4;
    }

    /** @param array<int, int> $ready */
    private function objectReady(array $ready, object $object, int $event): bool
    {
        return (($ready[spl_object_id($object)] ?? 0) & $event) !== 0;
    }

    private function openCriticalStream(string $name): PhpQuicStream
    {
        $stream = $this->connection->openStream(false);
        if ($stream->bidirectional() || ($stream->id() & 0x03) !== 0x03) {
            throw new Http3Exception(
                ErrorCode::STREAM_CREATION_ERROR,
                sprintf('Local HTTP/3 %s stream is not server-initiated and unidirectional.', $name),
            );
        }

        return $stream;
    }

    /** @param array<int, array{0: object, 1: int}> $items */
    private function putPollItem(array &$items, object $object, int $events): void
    {
        $items[spl_object_id($object)] = [$object, $events];
    }

    private function queueDecoderInstructions(): void
    {
        $instructions = $this->state->takeLocalQpackDecoderInstructions();
        if ($instructions === '') {
            return;
        }
        if (strlen($instructions) > $this->limits->maxPendingQpackDecoderBytes - strlen($this->qpackDecoderPending)) {
            throw new Http3Exception(
                ErrorCode::EXCESSIVE_LOAD,
                'Pending local QPACK decoder instructions exceed the configured limit.',
            );
        }

        $this->qpackDecoderPending .= $instructions;
    }

    private function registerPeerStream(PhpQuicStream $stream): void
    {
        $streamId = $stream->id();
        if (isset($this->peerStreams[$streamId]) || ($streamId & 0x01) !== 0) {
            throw new Http3Exception(ErrorCode::STREAM_CREATION_ERROR, 'Invalid or duplicate peer QUIC stream id.');
        }

        $bidirectional = $stream->bidirectional();
        $expectedBidirectional = ($streamId & 0x02) === 0;
        if ($bidirectional !== $expectedBidirectional) {
            throw new Http3Exception(ErrorCode::STREAM_CREATION_ERROR, 'QUIC stream direction does not match its stream id.');
        }
        if ($bidirectional && $this->drainBoundary !== null && $streamId >= $this->drainBoundary) {
            $stream->reset(ErrorCode::REQUEST_REJECTED->value);

            return;
        }

        $this->peerStreams[$streamId] = $stream;
        if ($bidirectional) {
            $this->requestStreams[$streamId] = true;
            $this->transport->registerRequestStream($stream);
        }
    }

    private function requestPressured(int $streamId): bool
    {
        if (!isset($this->requestStreams[$streamId])) {
            return false;
        }

        return $this->state->requestStream($streamId)?->pressured() ?? false;
    }
}
