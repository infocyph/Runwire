<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Client;

use Infocyph\Runwire\Http\Headers;

/** Producer-side incremental upload contract, independent of transport version. */
interface ClientBodySourceInterface
{
    /** Whether the request body has permanently completed. */
    public function eof(): bool;

    /** Return up to maxBytes currently available without unbounded buffering. */
    public function read(int $maxBytes = PHP_INT_MAX): string;

    /** Return upload trailers, if any, after body completion. */
    public function trailers(): ?Headers;
}
