<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Http3\Qpack;

final readonly class EncodedFieldSection
{
    public function __construct(
        public string $block,
        public int $requiredInsertCount,
        public bool $blocking,
    ) {}
}
