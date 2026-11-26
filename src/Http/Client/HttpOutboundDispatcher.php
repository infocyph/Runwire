<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Client;

use Infocyph\Runwire\CancellationSource;
use Infocyph\Runwire\Http\Enum\ProtocolVersion;
use Infocyph\Runwire\Loop\LoopInterface;
use Infocyph\Runwire\Network\Connection;
use Infocyph\Runwire\Network\DialOptions;
use Infocyph\Runwire\Network\HappyEyeballsDialer;
use InvalidArgumentException;
use Throwable;

/**
 * Explicit cross-protocol outbound request owner with one cancellation contract.
 * No implicit protocol downgrade or hidden tenant reuse. Optional H1 pooled
 * and H2 multiplexed sessions must be caller-owned and independently verified.
 * A provided pre-established session never triggers a fallback dial.
 */
final readonly class HttpOutboundDispatcher
{
    /** Bind the dispatcher to the owner loop and verified dialer. */
    public function __construct(
        private LoopInterface $loop,
        private HappyEyeballsDialer $dialer,
        private ?PooledHttp1Session $h1Session = null,
        private ?Http2MultiplexConnection $h2Session = null,
    ) {}

    /**
     * Begin exactly one H1/H2 ALPN exchange; abort unused H1 sockets.
     * @param callable(int,\Infocyph\Runwire\Http\Headers):void $onHeaders
     * @param callable(string):void $onBody
     * @param callable(?Throwable):void $completion
     * @throws \Infocyph\Runwire\Exception\RuntimeUnavailableException
     * @throws \InvalidArgumentException
     * @throws \Throwable
     */
    public function send(
        ClientRequest $request,
        callable $onHeaders,
        callable $onBody,
        callable $completion,
        ?DialOptions $options = null,
    ): OutboundHttpHandle {
        $source = $request->cancellation === null
            ? new CancellationSource($request->deadline)
            : CancellationSource::linked($request->cancellation, $request->deadline);
        $handle = new OutboundHttpHandle($source);
        $owned = $request->withCancellation($source->token());

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
     * @throws \InvalidArgumentException
     * @throws \RuntimeException
     * @throws \LogicException
     * @throws \Throwable
     */
    private function dispatch(
        ClientRequest $request,
        OutboundHttpHandle $handle,
        callable $onHeaders,
        callable $onBody,
        callable $completion,
        ?DialOptions $options,
    ): void {
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
}
