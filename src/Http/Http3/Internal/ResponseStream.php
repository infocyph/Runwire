<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Http3\Internal;

use Closure;
use Infocyph\Runwire\Network\Internal\ByteBudget;
use Infocyph\Runwire\Network\Internal\ByteQueue;

/**
 * Stores mutable outbound state for one HTTP/3 response stream.
 */
final class ResponseStream
{
    public readonly ByteQueue $outbound;

    public ?Closure $drainCallback = null;

    public bool $ended = false;

    public bool $endPending = false;

    public bool $transportPressured = false;

    public bool $writePressured = false;

    /**
     * Create response-stream state for the supplied request stream ID.
     */
    public function __construct(public readonly int $id, ?ByteBudget $budget = null)
    {
        $this->outbound = new ByteQueue($budget);
    }
}
