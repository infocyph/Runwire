<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Http3\Qpack;

/**
 * Carries an encoded QPACK field section and its blocking metadata.
 */
final readonly class EncodedFieldSection
{
    /**
     * Create an encoded QPACK field section result.
     */
    public function __construct(
        public string $block,
        public int $requiredInsertCount,
        public bool $blocking,
    ) {}
}
