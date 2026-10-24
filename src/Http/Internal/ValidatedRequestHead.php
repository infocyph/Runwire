<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Internal;

use Infocyph\Runwire\Http\Headers;

/**
 * Carries normalized request-line metadata after protocol header validation.
 */
final readonly class ValidatedRequestHead
{
    /**
     * Create a validated request head.
     */
    public function __construct(
        public string $method,
        public string $target,
        public Headers $headers,
        public ?int $contentLength,
    ) {}
}
