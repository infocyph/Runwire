<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Http3\Qpack;

/**
 * Carries a decoded QPACK field section and its dynamic-table reference metadata.
 */
final readonly class DecodedFieldSection
{
    /** @param list<array{0: string, 1: string}> $fields */
    public function __construct(
        public array $fields,
        public int $requiredInsertCount,
        public int $base,
        public bool $dynamicReferenced,
    ) {}
}
