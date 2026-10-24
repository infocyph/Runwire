<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Client;

use Infocyph\Runwire\Http\Enum\ProtocolVersion;
use Infocyph\Runwire\Http\Headers;
use Infocyph\Runwire\Http\RequestBodyInterface;

/** Streaming response metadata and bounded, incremental body consumption. */
interface ClientResponseInterface
{
    /** The response body is consumed with the same streaming read contract. */
    public function body(): RequestBodyInterface;

    /** Abort the response body and release its owned stream. */
    public function close(): void;

    public function headers(): Headers;

    public function status(): int;

    public function version(): ProtocolVersion;
}
