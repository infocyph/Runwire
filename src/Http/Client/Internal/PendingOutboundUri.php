<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Client\Internal;

/** @internal Retains a URI for inspection until a destination policy authorizes it for sending. */
final readonly class PendingOutboundUri
{
    /** Capture the exact target for a later destination policy match. */
    public function __construct(public string $value) {}
}
