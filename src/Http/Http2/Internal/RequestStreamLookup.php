<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Http2\Internal;

use LogicException;

final class RequestStreamLookup
{
    private ?RequestStreamProcessor $processor = null;

    public function attach(RequestStreamProcessor $processor): void
    {
        if ($this->processor !== null) {
            throw new LogicException('HTTP/2 request stream lookup is already attached.');
        }
        $this->processor = $processor;
    }

    public function stream(int $id): ?Http2Stream
    {
        return $this->processor?->stream($id);
    }
}
