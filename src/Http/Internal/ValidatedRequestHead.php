<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Internal;

use Infocyph\Runwire\Http\Headers;

final readonly class ValidatedRequestHead
{
    public function __construct(
        public string $method,
        public string $target,
        public Headers $headers,
        public ?int $contentLength,
    ) {}
}
