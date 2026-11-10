<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Tests\Fixtures;

/** Mutable state shared between the native stream double and adapter assertions. */
abstract class QuicTransportState
{
    public bool $ended = false;

    public bool $failEnd = false;

    public bool $failWrite = false;

    public ?int $resetCode = null;

    public string $written = '';
}
