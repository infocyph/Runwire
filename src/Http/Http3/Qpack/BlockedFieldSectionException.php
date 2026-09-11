<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Http3\Qpack;

use RuntimeException;

final class BlockedFieldSectionException extends RuntimeException
{
    public function __construct(public readonly int $requiredInsertCount)
    {
        parent::__construct(sprintf('QPACK field section is blocked until insert count %d.', $requiredInsertCount));
    }
}
