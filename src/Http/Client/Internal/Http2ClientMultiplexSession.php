<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Client\Internal;

use Closure;
use Infocyph\Runwire\Http\Client\ClientRequest;
use Infocyph\Runwire\Http\Enum\ProtocolVersion;
use Infocyph\Runwire\Http\Http2\Enum\ErrorCode;
use Infocyph\Runwire\Http\Http2\Enum\FrameType;
use Infocyph\Runwire\Http\Http2\Frame;
use Infocyph\Runwire\Http\Http2\FrameWriter;
use Infocyph\Runwire\Http\Http2\Hpack\Decoder;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * @internal Connection-scoped H2 request/response stream state, without socket ownership.
 *
 * The transport must verify TLS/ALPN, feed frames in wire order and own its
 * deadlines, write budget and connection shutdown. This is NOT an active
 * network multiplexer until the shared transport dispatcher is integrated.
 */
final class Http2ClientMultiplexSession
{
    private readonly Decoder $decoder;

    private readonly Http2ClientHandshake $handshake;

    private ?string $authority = null;

    private bool $closed = false;

    private ?int $continuationStreamId = null;

    private int $lastStreamId = -1;

    /** @var array<int, true> */
    private array $retired = [];

    private bool $started = false;

    /**
     * @var array<int, array{
     *   request:ClientRequest, response:Http2ClientResponseParser,
     *   headers:Closure, body:Closure, complete:Closure
     * }>
     */
    private array $streams = [];

    /** Construct a bounded protocol-only session with no implicit socket ownership. */
    public function __construct(private readonly int $maxStreams = 16)
    {
        if ($maxStreams < 1 || $maxStreams > 32) {
            throw new InvalidArgumentException('H2 multiplex protocol stream capacity must be 1–32.');
        }
        $this->decoder = new Decoder();
        $this->handshake = new Http2ClientHandshake();
    }

    /** Number of independently live responses, not a TCP connection count. */
    public function activeCount(): int
    {
        return count($this->streams);
    }

    /** Retire one stream without aborting a live sibling; transport must send returned RST. */
    public function cancel(int $streamId): string
    {
        if (!isset($this->streams[$streamId])) {
            throw new InvalidArgumentException('Cannot cancel an unknown H2 client stream.');
        }
        if (count($this->retired) >= $this->maxStreams * 4) {
            throw new RuntimeException('H2 canceled-stream tombstone budget exhausted; rotate the connection.');
        }

        $this->retired[$streamId] = true;
        $this->finish($streamId, new RuntimeException('HTTP/2 client stream canceled.'));

        return FrameWriter::encode(FrameWriter::rstStream($streamId, ErrorCode::CANCEL));
    }

    /** Settle each unfinished response once on connection terminal failure. */
    public function close(?Throwable $error = null): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        $this->retired = [];
        $callbackError = null;
        foreach (array_keys($this->streams) as $streamId) {
            try {
                $this->finish($streamId, $error ?? new RuntimeException('HTTP/2 client connection closed.'));
            } catch (Throwable $failure) {
                $callbackError ??= $failure;
            }
        }
        if ($callbackError !== null) {
            throw $callbackError;
        }
    }

    /** Validated peer SETTINGS allow new requests on this verified socket. */
    public function ready(): bool
    {
        return $this->started && !$this->closed && $this->handshake->ready();
    }

    /** Consume one frame in wire order, returning bounded connection/stream credit. */
    public function receive(Frame $frame): string
    {
        if (!$this->started || $this->closed) {
            throw new RuntimeException('H2 multiplex protocol session is not active.');
        }
        if ($this->continuationStreamId !== null
            && ($frame->knownType() !== FrameType::CONTINUATION
                || $frame->streamId !== $this->continuationStreamId)) {
            throw new RuntimeException('HTTP/2 connection interleaved a frame during CONTINUATION.');
        }
        if ($frame->streamId === 0) {
            return $this->control($frame);
        }
        if (isset($this->retired[$frame->streamId])) {
            return $this->retiredFrame($frame);
        }

        return $this->streamFrame($frame);
    }

    /**
     * Admit an independent bodyless request after peer SETTINGS validation.
     *
     * @param callable(int,\Infocyph\Runwire\Http\Headers):void $onHeaders
     * @param callable(string):void $onBody
     * @param callable(?Throwable):void $onComplete
     * @return array{stream:int,wire:string}
     */
    public function request(
        ClientRequest $request,
        callable $onHeaders,
        callable $onBody,
        callable $onComplete,
    ): array {
        if (!$this->started || $this->closed || !$this->handshake->ready()
            || count($this->streams) >= $this->maxStreams
            || count($this->streams) >= ($this->handshake->peerMaxConcurrentStreams() ?? $this->maxStreams)
            || $this->lastStreamId > 0x7FFF_FFFD) {
            throw new RuntimeException('HTTP/2 multiplex request admission unavailable.');
        }
        $parts = parse_url($request->uri);
        if (!is_array($parts) || strtolower($parts['scheme'] ?? '') !== 'https'
            || !is_string($parts['host'] ?? null)
            || !in_array($request->method, ['GET', 'HEAD'], true)
            || $request->body !== null
            || $request->protocol->negotiate('h2', true) !== ProtocolVersion::HTTP_2) {
            throw new InvalidArgumentException('HTTP/2 multiplex request requires a verified bodyless HTTPS origin.');
        }
        $authority = strtolower($parts['host']);
        if (isset($parts['port']) && $parts['port'] !== 443) {
            $authority .= ':' . $parts['port'];
        }
        if ($this->authority !== null && $this->authority !== $authority) {
            throw new InvalidArgumentException('HTTP/2 multiplex connection cannot change its verified origin.');
        }
        $path = $parts['path'] ?? '/';
        if (isset($parts['query'])) {
            $path .= '?' . $parts['query'];
        }
        $streamId = $this->lastStreamId + 2;
        $wire = $this->handshake->requestOnStream(
            $streamId,
            $request->method,
            'https',
            $authority,
            $path,
            $request->headers,
        );
        $this->authority = $authority;
        $this->lastStreamId = $streamId;
        $this->streams[$streamId] = [
            'request' => $request,
            'response' => new Http2ClientResponseParser(streamId: $streamId, sharedDecoder: $this->decoder),
            'headers' => Closure::fromCallable($onHeaders),
            'body' => Closure::fromCallable($onBody),
            'complete' => Closure::fromCallable($onComplete),
        ];

        return ['stream' => $streamId, 'wire' => $wire];
    }

    /** Return client preface exactly once; caller owns actual transport writes. */
    public function start(): string
    {
        if ($this->started || $this->closed) {
            throw new RuntimeException('HTTP/2 multiplex client preface already started.');
        }
        $this->started = true;

        return $this->handshake->start();
    }

    /** Account for receive-window consumption without extending terminated streams. */
    private static function credit(Frame $frame): string
    {
        $bytes = $frame->knownType() === FrameType::DATA ? strlen($frame->payload) : 0;
        if ($bytes === 0) {
            return '';
        }
        $wire = FrameWriter::encode(FrameWriter::windowUpdate(0, $bytes));
        if (!$frame->hasFlag(0x1)) {
            $wire .= FrameWriter::encode(FrameWriter::windowUpdate($frame->streamId, $bytes));
        }

        return $wire;
    }

    /** Enforce connection SETTINGS and reject GOAWAY streams above peer last ID. */
    private function control(Frame $frame): string
    {
        $wire = $this->handshake->receive($frame);
        if ($frame->knownType() === FrameType::GOAWAY) {
            $lastAccepted = $this->handshake->peerGoAwayLastStreamId();
            foreach (array_keys($this->streams) as $streamId) {
                if ($lastAccepted !== null && $streamId > $lastAccepted) {
                    $this->finish($streamId, new RuntimeException('HTTP/2 peer GOAWAY refused this client stream.'));
                }
            }
        }

        return $wire;
    }

    private function finish(int $streamId, ?Throwable $error): void
    {
        $slot = $this->streams[$streamId] ?? null;
        if ($slot === null) {
            return;
        }
        unset($this->streams[$streamId]);
        ($slot['complete'])($error);
    }

    /** Drain previously canceled response frames without accepting new headers. */
    private function retiredFrame(Frame $frame): string
    {
        if ($frame->knownType() === FrameType::RST_STREAM) {
            if ($frame->flags !== 0 || strlen($frame->payload) !== 4) {
                throw new RuntimeException('H2 retired stream RST_STREAM requires four bytes and no flags.');
            }
            unset($this->retired[$frame->streamId]);

            return '';
        }
        if ($frame->knownType() !== FrameType::DATA || ($frame->flags & ~0x1) !== 0) {
            throw new RuntimeException('H2 retired stream received an unsafe control/header frame.');
        }
        if ($frame->hasFlag(0x1)) {
            unset($this->retired[$frame->streamId]);
        }
        $bytes = strlen($frame->payload);

        return $bytes > 0
            ? FrameWriter::encode(FrameWriter::windowUpdate(0, $bytes))
            : '';
    }

    /** Validate and complete one response without closing sibling exchanges. */
    private function streamFrame(Frame $frame): string
    {
        $slot = $this->streams[$frame->streamId] ?? null;
        if ($slot === null) {
            throw new RuntimeException('HTTP/2 peer used an unknown client response stream.');
        }
        $this->trackHeaderBlock($frame);
        if ($slot['request']->method === 'HEAD' && $frame->knownType() === FrameType::DATA
            && $frame->payload !== '') {
            throw new RuntimeException('HTTP/2 HEAD response must not contain body bytes.');
        }

        try {
            $slot['response']->push($frame, $slot['headers'], $slot['body']);
        } catch (RuntimeException $error) {
            if ($frame->knownType() !== FrameType::RST_STREAM || !$slot['response']->done()) {
                throw $error;
            }
            $this->finish($frame->streamId, $error);

            return '';
        }
        if ($slot['response']->done()) {
            $this->finish($frame->streamId, null);
        }

        return self::credit($frame);
    }

    /** Never allow sibling frames to interleave an incomplete header block. */
    private function trackHeaderBlock(Frame $frame): void
    {
        if ($frame->knownType() === FrameType::HEADERS && !$frame->hasFlag(0x4)) {
            $this->continuationStreamId = $frame->streamId;
        } elseif ($frame->knownType() === FrameType::CONTINUATION && $frame->hasFlag(0x4)) {
            $this->continuationStreamId = null;
        }
    }
}
