<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Client\Internal;

use Closure;
use Infocyph\Runwire\CancellationSubscription;
use Infocyph\Runwire\Http\Client\ClientRequest;

/** @internal One independently cancellable HTTP/3 request on a shared QUIC session. */
final class Http3ClientSiblingExchange
{
    public readonly Closure $completion;

    public readonly Closure $onBody;

    public readonly Closure $onHeaders;

    public readonly ?Closure $onTrailers;

    public ?CancellationSubscription $cancellation = null;

    public bool $peerEof = false;

    public bool $readActive = false;

    public ?Http3ClientResponseStream $response = null;

    public bool $sent = false;

    public int $stream = 0;

    public ?int $timeout = null;

    public int $uploadedBytes = 0;

    /**
     * @param callable(int,\Infocyph\Runwire\Http\Headers):void $onHeaders
     * @param callable(string):void $onBody
     * @param callable(?\Throwable):void $completion
     * @param null|callable(\Infocyph\Runwire\Http\Headers):void $onTrailers
     */
    public function __construct(
        public readonly ClientRequest $request,
        callable $onHeaders,
        callable $onBody,
        callable $completion,
        ?callable $onTrailers,
    ) {
        $this->completion = Closure::fromCallable($completion);
        $this->onBody = Closure::fromCallable($onBody);
        $this->onHeaders = Closure::fromCallable($onHeaders);
        $this->onTrailers = $onTrailers === null ? null : Closure::fromCallable($onTrailers);
    }
}
