<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Tests\Fixtures;

/** Mutable connection state observed across independent worker commands. */
abstract class QuicWorkerState
{
    public bool $closed = false;

    /** @var list<object> */
    public array $incoming = [];

    public bool $lastBidirectional = true;

    public int $pollCalls = 0;
}
