<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Http2\Internal;

use Infocyph\Runwire\Http\Http2\ErrorCode;
use RuntimeException;

final class ConnectionError extends RuntimeException
{
    public function __construct(public readonly ErrorCode $errorCode, string $message)
    {
        parent::__construct($message);
    }
}
