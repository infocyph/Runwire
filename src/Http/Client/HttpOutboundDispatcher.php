<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Client;

use Infocyph\Runwire\CancellationSource;
use Infocyph\Runwire\Exception\RuntimeUnavailableException;
use Infocyph\Runwire\Http\Enum\ProtocolVersion;
use Infocyph\Runwire\Loop\LoopInterface;
use Infocyph\Runwire\Network\Connection;
use Infocyph\Runwire\Network\DialOptions;
use Infocyph\Runwire\Network\HappyEyeballsDialer;
use Infocyph\Runwire\Network\QuicClientOptions;
use InvalidArgumentException;
use Throwable;

/**
 * Explicit cross-protocol outbound request owner with one cancellation contract.
 * No transparent H3 downgrade or hidden tenant reuse. Optional H1 pooled,
 * H2 multiplexed and H3 sibling sessions must be caller-owned and independently
 * verified. A provided pre-established session never triggers a fallback dial.
 */
final readonly class HttpOutboundDispatcher
{
    /** Bind the dispatcher to the owner loop and verified dialer. */
    public function __construct(
        private LoopInterface $loop,
        private HappyEyeballsDialer $dialer,
        private ?QuicClientOptions $quic = null,
        private ?PooledHttp1Session $h1Session = null,
        private ?Http2MultiplexConnection $h2Session = null,
        private ?Http3OutboundClient $h3Session = null,
    ) {}

    /**
     * Begin exactly one H1/H2 ALPN or H3 native exchange; abort unused H1 sockets.
     * @param callable(int,\Infocyph\Runwire\Http\Headers):void $onHeaders
     * @param callable(string):void $onBody
     * @param callable(?Throwable):void $completion
     */
    public function send(
        ClientRequest $request,
        callable $onHeaders,
        callable $onBody,
        callable $completion,
        ?DialOptions $options = null,
    ): OutboundHttpHandle {
        $isH3 = $request->protocol->preferred === ProtocolVersion::HTTP_3;
        if ($isH3 && (($this->quic === null && $this->h3Session === null)
            || $request->protocol->allowedVersions !== [ProtocolVersion::HTTP_3])) {
            throw new RuntimeUnavailableException('H3 needs explicit QUIC options and an h3-only protocol policy.');
        }
        if (!$isH3 && in_array(ProtocolVersion::HTTP_3, $request->protocol->allowedVersions, true)) {
            throw new RuntimeUnavailableException('Mixed TCP/QUIC downgrade is not implemented; select explicit H3.');
        }
        if ($isH3 && $options !== null) {
            throw new InvalidArgumentException('H3 uses its own QUIC connection policy, not TCP dial options.');
        }

        $source = $request->cancellation === null
            ? new CancellationSource($request->deadline)
            : CancellationSource::linked($request->cancellation, $request->deadline);
        $handle = new OutboundHttpHandle($source);
        $owned = new ClientRequest(
            $request->method,
            $request->uri,
            $request->headers,
            $request->body,
            $request->protocol,
            $source->token(),
            $request->deadline,
        );

        try {
            $this->dispatch($owned, $handle, $onHeaders, $onBody, $completion, $options);
        } catch (Throwable $error) {
            $handle->complete();

            throw $error;
        }

        return $handle;
    }

    /**
     * @param callable(?Throwable):void $completion
     * @return \Closure(?Throwable):void
     */
    private static function settle(OutboundHttpHandle $handle, callable $completion): \Closure
    {
        return static function (?Throwable $error) use ($handle, $completion): void {
            $handle->complete();
            $completion($error);
        };
    }

    /**
     * @param callable(int,\Infocyph\Runwire\Http\Headers):void $onHeaders
     * @param callable(string):void $onBody
     * @param callable(?Throwable):void $completion
     */
    private function dispatch(
        ClientRequest $request,
        OutboundHttpHandle $handle,
        callable $onHeaders,
        callable $onBody,
        callable $completion,
        ?DialOptions $options,
    ): void {
        if ($request->protocol->preferred === ProtocolVersion::HTTP_3) {
            $this->sendH3($request, $handle, $onHeaders, $onBody, $completion);

            return;
        }
        if ($this->h2Session !== null && $request->protocol->preferred === ProtocolVersion::HTTP_2
            && $request->protocol->allowedVersions === [ProtocolVersion::HTTP_2]) {
            if ($options !== null) {
                throw new InvalidArgumentException('An established H2 session cannot accept per-request TLS/dial overrides.');
            }
            $this->h2Session->request($request, $onHeaders, $onBody, self::settle($handle, $completion));

            return;
        }
        if ($this->h1Session !== null && $request->protocol->preferred === ProtocolVersion::HTTP_1_1
            && $request->protocol->allowedVersions === [ProtocolVersion::HTTP_1_1]) {
            if ($options !== null) {
                throw new InvalidArgumentException('Pooled H1 dial policy is fixed by its session, not a request override.');
            }
            $this->h1Session->send($request, $onHeaders, $onBody, self::settle($handle, $completion));

            return;
        }
        HttpNegotiatingOutboundClient::connect(
            $this->loop,
            $this->dialer,
            $request,
            $onHeaders,
            $onBody,
            static function (?Connection $conn, ?Throwable $error) use ($handle, $completion): void {
                $conn?->abort();
                $handle->complete();
                $completion($error);
            },
            $options,
        );
    }

    /**
     * @param callable(int,\Infocyph\Runwire\Http\Headers):void $onHeaders
     * @param callable(string):void $onBody
     * @param callable(?Throwable):void $completion
     */
    private function sendH3(
        ClientRequest $request,
        OutboundHttpHandle $handle,
        callable $onHeaders,
        callable $onBody,
        callable $completion,
    ): void {
        if ($this->h3Session !== null) {
            $this->h3Session->enqueue($request, $onHeaders, $onBody, self::settle($handle, $completion));

            return;
        }
        if ($this->quic === null) {
            throw new RuntimeUnavailableException('Missing native QUIC client policy.');
        }
        Http3OutboundClient::connect(
            $this->loop,
            $request,
            $this->quic,
            $onHeaders,
            $onBody,
            self::settle($handle, $completion),
        );
    }
}
