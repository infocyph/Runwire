<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Http3;

use Infocyph\Runwire\Exception\ProtocolException;
use Infocyph\Runwire\Http\Http3\Enum\ErrorCode;
use Throwable;

final class Http3Exception extends ProtocolException
{
    public function __construct(
        public readonly ErrorCode $errorCode,
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $errorCode->value, $previous);
    }
}
