<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Http3;

use Closure;
use Infocyph\Runwire\Http\HttpRequest;
use Infocyph\Runwire\Http\ProtocolVersion;
use Infocyph\Runwire\Http\ResponseWriterInterface;
use Infocyph\Runwire\Http\Http3\Internal\ConnectionState;
use LogicException;

final class Http3Session
{
    /** @var array<int, true> */
    private array $dispatchedRequestStreams = [];

    /** @var Closure(HttpRequest, ResponseWriterInterface): void */
    private readonly Closure $handler;

    /** @var array<int, true> */
    private array $pendingRequestStreams = [];

    /** @var Closure(int, string): ResponseWriterInterface */
    private readonly Closure $writerFactory;

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

    public function cancelRequestStream(int $streamId): void
    {
        unset($this->pendingRequestStreams[$streamId]);
        $this->state->cancelRequestStream($streamId);
    }

    public function finishPeerUnidirectional(int $streamId): void
    {
        $this->state->finishPeerUnidirectional($streamId);
    }

    public function finishRequestStream(int $streamId): void
    {
        $this->state->finishRequestStream($streamId);
        $this->dispatchReadyRequests();
    }

    public function pushPeerUnidirectional(int $streamId, string $bytes): void
    {
        $this->state->pushPeerUnidirectional($streamId, $bytes);
        $this->dispatchReadyRequests();
    }

    public function pushRequestStream(int $streamId, string $bytes): void
    {
        $this->state->pushRequestStream($streamId, $bytes);
        if (!isset($this->dispatchedRequestStreams[$streamId])) {
            $this->pendingRequestStreams[$streamId] = true;
        }
        $this->dispatchReadyRequests();
    }

    public function releaseRequestStream(int $streamId): void
    {
        unset($this->pendingRequestStreams[$streamId]);
        $this->state->releaseRequestStream($streamId);
    }

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
            if (!$writer instanceof ResponseWriterInterface) {
                throw new LogicException('HTTP/3 writer factory must return ResponseWriterInterface.');
            }

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
