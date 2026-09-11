<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Http2\Internal;

use Infocyph\Runwire\Http\Http2\ErrorCode;
use Infocyph\Runwire\Http\Http2\Http2Limits;

final class PendingHeaderBlock
{
    private string $block;
    private int $continuations = 0;

    public function __construct(
        public readonly int $streamId,
        string $initial,
        public readonly bool $endStream,
        public readonly bool $trailers,
        private readonly Http2Limits $limits,
    ) {
        if (strlen($initial) > $limits->maxHeaderBlockBytes) {
            throw new ConnectionError(ErrorCode::ENHANCE_YOUR_CALM, 'HTTP/2 compressed header block exceeds configured limit.');
        }
        $this->block = $initial;
    }

    public function append(int $streamId, string $fragment): void
    {
        if ($streamId !== $this->streamId) {
            throw new ConnectionError(ErrorCode::PROTOCOL_ERROR, 'HTTP/2 CONTINUATION stream does not match the open header block.');
        }
        ++$this->continuations;
        if ($this->continuations > $this->limits->maxContinuationFrames) {
            throw new ConnectionError(ErrorCode::ENHANCE_YOUR_CALM, 'HTTP/2 header block uses too many CONTINUATION frames.');
        }
        if (strlen($fragment) > $this->limits->maxHeaderBlockBytes - strlen($this->block)) {
            throw new ConnectionError(ErrorCode::ENHANCE_YOUR_CALM, 'HTTP/2 compressed header block exceeds configured limit.');
        }
        $this->block .= $fragment;
    }

    public function bytes(): string
    {
        return $this->block;
    }
}
