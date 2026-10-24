<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Http1\Internal;

/**
 * Holds validated HTTP/1.1 request framing metadata.
 */
final readonly class RequestHead
{
    /**
     * Create validated request-head framing metadata.
     */
    public function __construct(
        public int $contentLength,
        public bool $chunked,
        public bool $expectContinue,
        public bool $closeRequested,
    ) {}
}
