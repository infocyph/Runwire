<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Http3\Qpack;

/**
 * Associates a newly unblocked QPACK field section with its request stream.
 */
final readonly class ReadyFieldSection
{
    /**
     * Create a ready field-section result for a stream.
     */
    public function __construct(
        public int $streamId,
        public DecodedFieldSection $section,
    ) {}
}
