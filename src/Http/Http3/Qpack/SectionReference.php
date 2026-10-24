<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Http3\Qpack;

/**
 * Tracks dynamic-table entries referenced by an outstanding encoded field section.
 */
final readonly class SectionReference
{
    /** @param list<int> $entries */
    public function __construct(
        public int $requiredInsertCount,
        public array $entries,
        public bool $blocking,
    ) {}
}
