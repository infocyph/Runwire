<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Http2\Internal;

use LogicException;

/**
 * Resolves active HTTP/2 request streams through an attached processor.
 */
final class RequestStreamLookup
{
    private ?RequestStreamProcessor $processor = null;

    /**
     * Attach the request stream processor once.
     */
    public function attach(RequestStreamProcessor $processor): void
    {
        if ($this->processor !== null) {
            throw new LogicException('HTTP/2 request stream lookup is already attached.');
        }
        $this->processor = $processor;
    }

    /**
     * Return the active stream for an ID when present.
     */
    public function stream(int $id): ?Http2Stream
    {
        return $this->processor?->stream($id);
    }
}
