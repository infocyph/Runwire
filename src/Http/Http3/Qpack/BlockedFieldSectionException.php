<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Http3\Qpack;

use RuntimeException;

/**
 * Reports a QPACK field section blocked on a future dynamic-table insert count.
 */
final class BlockedFieldSectionException extends RuntimeException
{
    /**
     * Create a blocked-field-section exception for the required insert count.
     */
    public function __construct(public readonly int $requiredInsertCount)
    {
        parent::__construct(sprintf('QPACK field section is blocked until insert count %d.', $requiredInsertCount));
    }
}
