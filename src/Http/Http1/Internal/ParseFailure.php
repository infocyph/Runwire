<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Http1\Internal;

use Infocyph\Runwire\Exception\ProtocolException;

/**
 * Carries the HTTP status associated with an HTTP/1.1 parse failure.
 */
final class ParseFailure extends ProtocolException
{
    /**
     * Create a parse failure with the response status to emit.
     */
    public function __construct(
        public readonly int $status,
        string $message,
    ) {
        parent::__construct($message);
    }
}
