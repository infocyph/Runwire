<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Http3\Internal;

use Closure;
use Infocyph\Runwire\Network\Internal\ByteQueue;

final class ResponseStream
{
    public readonly ByteQueue $outbound;

    public ?Closure $drainCallback = null;

    public bool $ended = false;

    public bool $endPending = false;

    public bool $transportPressured = false;

    public bool $writePressured = false;

    public function __construct(public readonly int $id)
    {
        $this->outbound = new ByteQueue();
    }
}
