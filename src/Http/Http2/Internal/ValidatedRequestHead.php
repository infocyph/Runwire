<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Http2\Internal;

use Infocyph\Runwire\Http\Headers;

/**
 * Holds a validated HTTP/2 request head.
 */
final readonly class ValidatedRequestHead
{
    /**
     * Create normalized request-head metadata.
     */
    public function __construct(
        public string $method,
        public string $target,
        public Headers $headers,
        public ?int $contentLength,
    ) {}
}
