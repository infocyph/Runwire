<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Client\Internal;

use Infocyph\Runwire\Http\Client\ClientRequest;
use Throwable;

/** @internal Mutable state shared only by callbacks of one outbound exchange. */
final class Http1PolicyAttempt
{
    public ?Throwable $headError = null;

    public ?ClientRequest $redirect = null;

    public bool $responseStarted = false;

    public bool $suppressBody = false;
}
