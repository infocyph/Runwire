<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Http2\Internal;

use Infocyph\Runwire\Http\Http2\Enum\ErrorCode;
use RuntimeException;

/**
 * Reports a connection-scoped HTTP/2 protocol failure.
 */
final class ConnectionError extends RuntimeException
{
    /**
     * Create a connection error with its HTTP/2 error code.
     */
    public function __construct(public readonly ErrorCode $errorCode, string $message)
    {
        parent::__construct($message);
    }
}
