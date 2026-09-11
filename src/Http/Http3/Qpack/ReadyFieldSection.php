<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Http3\Qpack;

final readonly class ReadyFieldSection
{
    public function __construct(
        public int $streamId,
        public DecodedFieldSection $section,
    ) {}
}
