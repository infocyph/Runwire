<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Http2\Internal;

use Infocyph\Runwire\Http\Http2\Enum\ErrorCode;
use Infocyph\Runwire\Http\Http2\Http2Limits;
use Infocyph\Runwire\Network\Internal\ByteBudget;

/**
 * Accumulates a bounded HTTP/2 HEADERS/CONTINUATION block.
 */
final class PendingHeaderBlock
{
    private string $block;

    private int $continuations = 0;

    /**
     * Create a pending compressed header block.
     */
    public function __construct(
        public readonly int $streamId,
        string $initial,
        public readonly bool $endStream,
        public readonly bool $trailers,
        private readonly Http2Limits $limits,
        private readonly ?ByteBudget $budget = null,
    ) {
        if (strlen($initial) > $limits->maxHeaderBlockBytes) {
            throw new ConnectionError(ErrorCode::ENHANCE_YOUR_CALM, 'HTTP/2 compressed header block exceeds configured limit.');
        }
        if ($this->budget !== null && !$this->budget->reserve(strlen($initial))) {
            throw new ConnectionError(ErrorCode::ENHANCE_YOUR_CALM, 'Worker queued-byte budget is exhausted.');
        }
        $this->block = $initial;
    }

    public function __destruct()
    {
        $this->budget?->release(strlen($this->block));
        $this->block = '';
    }

    /**
     * Append one continuation fragment.
     */
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
        if ($this->budget !== null && !$this->budget->reserve(strlen($fragment))) {
            throw new ConnectionError(ErrorCode::ENHANCE_YOUR_CALM, 'Worker queued-byte budget is exhausted.');
        }
        $this->block .= $fragment;
    }

    /**
     * Return the complete compressed header block bytes accumulated so far.
     */
    public function bytes(): string
    {
        return $this->block;
    }
}
