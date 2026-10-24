<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Client\Internal;

use Closure;
use Infocyph\Runwire\Http\Client\ClientRequest;
use Infocyph\Runwire\Http\Enum\ProtocolVersion;
use Infocyph\Runwire\Http\Headers;
use Infocyph\Runwire\Http\Http2\Enum\FrameType;
use Infocyph\Runwire\Http\Http2\Frame;
use Infocyph\Runwire\Http\Http2\FrameWriter;
use InvalidArgumentException;
use RuntimeException;

/**
 * @internal One-request HTTP/2 protocol session on a separately verified TLS
 * connection. This is not an HTTP/2 dialer, connection pool or multiplexer.
 */
final class Http2ClientSession
{
    private readonly Closure $bodyCallback;

    private readonly Closure $headersCallback;

    private readonly Http2ClientHandshake $handshake;

    private readonly Http2ClientResponseParser $response;

    private bool $dispatched = false;

    private bool $started = false;

    /**
     * @param callable(int,Headers):void $onHeaders
     * @param callable(string):void $onBody
     */
    public function __construct(
        private readonly ClientRequest $request,
        callable $onHeaders,
        callable $onBody,
    ) {
        $parts = parse_url($request->uri);
        if (!is_array($parts) || strtolower($parts['scheme'] ?? '') !== 'https'
            || !in_array(strtoupper($request->method), ['GET', 'HEAD'], true)
            || strtoupper($request->method) !== $request->method
            || $request->body !== null
            || $request->protocol->negotiate('h2', true) !== ProtocolVersion::HTTP_2) {
            throw new InvalidArgumentException('HTTP/2 client session requires verified HTTPS, permitted H2, and a bodyless GET/HEAD.');
        }
        $this->headersCallback = Closure::fromCallable($onHeaders);
        $this->bodyCallback = Closure::fromCallable($onBody);
        $this->handshake = new Http2ClientHandshake();
        $this->response = new Http2ClientResponseParser();
    }

    /** Observe complete response ownership after validated END_STREAM. */
    public function done(): bool
    {
        return $this->response->done();
    }

    /** Deliver a fully parsed H2 frame and return bounded control/flow-credit bytes. */
    public function receive(Frame $frame): string
    {
        if (!$this->started || $this->response->done()) {
            throw new RuntimeException('HTTP/2 session is not accepting frames.');
        }
        if ($frame->streamId === 0) {
            return $this->control($frame);
        }
        if (!$this->dispatched || $frame->streamId !== 1) {
            throw new RuntimeException('HTTP/2 response arrived on an unexpected stream.');
        }
        if ($this->request->method === 'HEAD' && $frame->knownType() === FrameType::DATA && $frame->payload !== '') {
            throw new RuntimeException('HTTP/2 HEAD response must not contain body bytes.');
        }
        $this->response->push($frame, $this->headersCallback, $this->bodyCallback);

        return !$this->response->done() && $frame->knownType() === FrameType::DATA && strlen($frame->payload) > 0
            ? FrameWriter::encode(FrameWriter::windowUpdate(0, strlen($frame->payload)))
                . FrameWriter::encode(FrameWriter::windowUpdate(1, strlen($frame->payload)))
            : '';
    }

    /** Emit the one-shot H2 client connection preface and SETTINGS. */
    public function start(): string
    {
        if ($this->started) {
            throw new RuntimeException('HTTP/2 client session has already started.');
        }
        $this->started = true;

        return $this->handshake->start();
    }

    private function control(Frame $frame): string
    {
        $wire = $this->handshake->receive($frame);
        if (!$this->handshake->ready() && !$this->response->done()) {
            throw new RuntimeException('HTTP/2 peer closed or rejected the session before END_STREAM.');
        }
        if ($this->dispatched) {
            return $wire;
        }

        $parts = parse_url($this->request->uri);
        if (!is_array($parts) || !isset($parts['host'])) {
            throw new RuntimeException('HTTP/2 request origin disappeared.');
        }
        $authority = $parts['host'];
        if (isset($parts['port']) && $parts['port'] !== 443) {
            $authority .= ':' . $parts['port'];
        }
        $path = ($parts['path'] ?? '/');
        if (isset($parts['query'])) {
            $path .= '?' . $parts['query'];
        }
        $requestWire = $this->handshake->firstRequest($this->request->method, 'https', $authority, $path, $this->request->headers);
        $this->dispatched = true;

        return $wire . $requestWire;
    }
}
