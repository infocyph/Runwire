<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Http1\Internal;

final readonly class RequestHead
{
    public function __construct(
        public int $contentLength,
        public bool $chunked,
        public bool $expectContinue,
        public bool $closeRequested,
    ) {
    }
}
