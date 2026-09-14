<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Exception;

use Infocyph\Runwire\Runtime\Enum\CancellationReason;
use RuntimeException;

/**
 * Reports cancellation together with its runtime reason.
 */
final class CancelledException extends RuntimeException
{
    /**
     * Create a cancellation exception for the supplied reason.
     */
    public function __construct(public readonly CancellationReason $reason)
    {
        parent::__construct(sprintf('Operation cancelled: %s.', $reason->value));
    }
}
