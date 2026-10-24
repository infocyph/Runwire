<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Http2\Internal;

use Infocyph\Runwire\Http\Http2\Enum\ErrorCode;
use RuntimeException;

/**
 * Reports a stream-scoped HTTP/2 protocol failure.
 */
final class StreamError extends RuntimeException
{
    /**
     * Create a stream error with its stream ID and HTTP/2 error code.
     */
    public function __construct(
        public readonly int $streamId,
        public readonly ErrorCode $errorCode,
        string $message,
    ) {
        parent::__construct($message);
    }
}
