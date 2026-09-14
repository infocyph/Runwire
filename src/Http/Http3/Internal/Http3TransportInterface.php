<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Http3\Internal;

/**
 * Defines the transport operations required by the HTTP/3 response scheduler.
 */
interface Http3TransportInterface
{
    /**
     * Finish a request stream after all queued response bytes are written.
     */
    public function finishRequestStream(int $streamId): void;

    /**
     * Write bytes to the local QPACK encoder stream and return the accepted byte count.
     */
    public function writeQpackEncoder(string $bytes): int;

    /**
     * Write response bytes to a request stream and return the accepted byte count.
     */
    public function writeRequestStream(int $streamId, string $bytes): int;
}
