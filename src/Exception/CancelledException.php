<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Exception;

use Infocyph\Runwire\Runtime\Enum\CancellationReason;
use RuntimeException;

final class CancelledException extends RuntimeException
{
    public function __construct(public readonly CancellationReason $reason)
    {
        parent::__construct(sprintf('Operation cancelled: %s.', $reason->value));
    }
}
