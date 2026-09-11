<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http;

use Closure;
use Infocyph\Runwire\Http\Http1\Http1Connection;
use Infocyph\Runwire\Http\Http1\Http1Limits;
use Infocyph\Runwire\Http\Http2\Http2Connection;
use Infocyph\Runwire\Http\Http2\Http2Limits;
use Infocyph\Runwire\Loop\LoopInterface;
use Infocyph\Runwire\Network\CloseReason;
use Infocyph\Runwire\Network\Connection;

final class NativeHttpConnection
{
    private function __construct(
        private readonly Http1Connection|Http2Connection $protocol,
        public readonly ProtocolVersion $version,
    ) {
    }

    /**
     * @param callable(HttpRequest, ResponseWriterInterface): void $handler
     */
    public static function attach(
        LoopInterface $loop,
        Connection $connection,
        callable $handler,
        ?Http1Limits $http1Limits = null,
        ?Http2Limits $http2Limits = null,
    ): ?self {
        $consumer = Closure::fromCallable($handler);
        $protocol = $connection->negotiatedProtocol();

        if ($protocol === 'h2') {
            return new self(
                new Http2Connection($loop, $connection, $http2Limits ?? new Http2Limits(), $consumer),
                ProtocolVersion::HTTP_2,
            );
        }

        if ($protocol === null || $protocol === '' || $protocol === 'http/1.1') {
            return new self(
                new Http1Connection($loop, $connection, $http1Limits ?? new Http1Limits(), $consumer),
                ProtocolVersion::HTTP_1_1,
            );
        }

        $connection->abort(CloseReason::PROTOCOL_ERROR);
        return null;
    }

    public function drain(): void
    {
        $this->protocol->drain();
    }
}
