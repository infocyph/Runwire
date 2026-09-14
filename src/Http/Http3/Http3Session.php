<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Http3;

use Closure;
use Infocyph\Runwire\Http\Enum\ProtocolVersion;
use Infocyph\Runwire\Http\Http3\Internal\ConnectionState;
use Infocyph\Runwire\Http\HttpRequest;
use Infocyph\Runwire\Http\ResponseWriterInterface;

/**
 * Coordinates HTTP/3 stream input with request dispatch and response writer creation.
 */
final class Http3Session
{
    /** @var Closure(HttpRequest, ResponseWriterInterface): void */
    private readonly Closure $handler;

    /** @var Closure(int, string): ResponseWriterInterface */
    private readonly Closure $writerFactory;

    /** @var array<int, true> */
    private array $dispatchedRequestStreams = [];

    /** @var array<int, true> */
    private array $pendingRequestStreams = [];

    /**
     * @param callable(HttpRequest, ResponseWriterInterface): void $handler
     * @param callable(int, string): ResponseWriterInterface $writerFactory
     */
    public function __construct(
        private readonly ConnectionState $state,
        callable $handler,
        callable $writerFactory,
        private readonly ?string $peerAddress = null,
        private readonly ?string $localAddress = null,
    ) {
        /** @var Closure(HttpRequest, ResponseWriterInterface): void $handlerClosure */
        $handlerClosure = Closure::fromCallable($handler);
        $this->handler = $handlerClosure;
        /** @var Closure(int, string): ResponseWriterInterface $factoryClosure */
        $factoryClosure = Closure::fromCallable($writerFactory);
        $this->writerFactory = $factoryClosure;
    }

    /**
     * Cancel and release a tracked request stream.
     */
    public function cancelRequestStream(int $streamId): void
    {
        unset($this->pendingRequestStreams[$streamId]);
        $this->state->cancelRequestStream($streamId);
    }

    /**
     * Mark a peer unidirectional stream as finished.
     */
    public function finishPeerUnidirectional(int $streamId): void
    {
        $this->state->finishPeerUnidirectional($streamId);
    }

    /**
     * Mark a request stream as finished and dispatch newly ready requests.
     */
    public function finishRequestStream(int $streamId): void
    {
        $this->state->finishRequestStream($streamId);
        $this->dispatchReadyRequests();
    }

    /**
     * Feed bytes from a peer unidirectional stream into connection state.
     */
    public function pushPeerUnidirectional(int $streamId, string $bytes): void
    {
        $this->state->pushPeerUnidirectional($streamId, $bytes);
        $this->dispatchReadyRequests();
    }

    /**
     * Feed bytes from a request stream and dispatch requests when their heads are ready.
     */
    public function pushRequestStream(int $streamId, string $bytes): void
    {
        $this->state->pushRequestStream($streamId, $bytes);
        if (!isset($this->dispatchedRequestStreams[$streamId])) {
            $this->pendingRequestStreams[$streamId] = true;
        }
        $this->dispatchReadyRequests();
    }

    /**
     * Release a request stream after response processing completes.
     */
    public function releaseRequestStream(int $streamId): void
    {
        unset($this->pendingRequestStreams[$streamId]);
        $this->state->releaseRequestStream($streamId);
    }

    /**
     * Return the underlying HTTP/3 connection state.
     */
    public function state(): ConnectionState
    {
        return $this->state;
    }

    private function dispatchReadyRequests(): void
    {
        foreach (array_keys($this->pendingRequestStreams) as $streamId) {
            $stream = $this->state->requestStream($streamId);
            if ($stream === null) {
                unset($this->pendingRequestStreams[$streamId]);

                continue;
            }

            $head = $stream->head();
            if ($head === null) {
                continue;
            }

            $writer = ($this->writerFactory)($streamId, $head->method);
            unset($this->pendingRequestStreams[$streamId]);
            $this->dispatchedRequestStreams[$streamId] = true;
            ($this->handler)(new HttpRequest(
                $head->method,
                $head->target,
                ProtocolVersion::HTTP_3,
                $head->headers,
                $stream->body(),
                $this->peerAddress,
                $this->localAddress,
                true,
            ), $writer);
        }
    }
}
