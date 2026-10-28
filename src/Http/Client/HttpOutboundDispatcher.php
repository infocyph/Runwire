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
 * No transparent H3 downgrade, automatic socket pool or hidden tenant reuse.
 */
final readonly class HttpOutboundDispatcher
{
    public function __construct(
        private LoopInterface $loop,
        private HappyEyeballsDialer $dialer,
        private ?QuicClientOptions $quic = null,
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
        if ($isH3 && ($this->quic === null
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
            $request->method, $request->uri, $request->headers, $request->body,
            $request->protocol, $source->token(), $request->deadline,
        );

        try {
            if ($isH3) {
                $this->sendH3($owned, $handle, $onHeaders, $onBody, $completion);

                return $handle;
            }

            HttpNegotiatingOutboundClient::connect(
                $this->loop, $this->dialer, $owned, $onHeaders, $onBody,
                static function (?Connection $connection, ?Throwable $error) use ($handle, $completion): void {
                    $connection?->abort();
                    $handle->complete();
                    $completion($error);
                },
                $options,
            );
        } catch (Throwable $error) {
            $handle->complete();

            throw $error;
        }

        return $handle;
    }

    /** @param callable(int,\Infocyph\Runwire\Http\Headers):void $onHeaders
     *  @param callable(string):void $onBody
     *  @param callable(?Throwable):void $completion
     */
    private function sendH3(
        ClientRequest $request,
        OutboundHttpHandle $handle,
        callable $onHeaders,
        callable $onBody,
        callable $completion,
    ): void {
        if ($this->quic === null) {
            throw new RuntimeUnavailableException('Missing native QUIC client policy.');
        }
        Http3OutboundClient::connect(
            $this->loop, $request, $this->quic, $onHeaders, $onBody,
            static function (?Throwable $error) use ($handle, $completion): void {
                $handle->complete();
                $completion($error);
            },
        );
    }
}
