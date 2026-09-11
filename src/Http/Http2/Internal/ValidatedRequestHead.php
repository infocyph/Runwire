<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Http2\Internal;

use Infocyph\Runwire\Http\Headers;

final readonly class ValidatedRequestHead
{
    public function __construct(
        public string $method,
        public string $target,
        public Headers $headers,
        public ?int $contentLength,
    ) {
    }
}
