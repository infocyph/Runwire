<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Coroutine\Exception;

final class BarrierBrokenException extends SynchronizationException
{
    public function __construct(public readonly int $generation)
    {
        parent::__construct(sprintf('Coroutine barrier generation %d was broken.', $generation));
    }
}
