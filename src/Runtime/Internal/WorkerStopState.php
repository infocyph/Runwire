<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Runtime\Internal;

use Infocyph\Runwire\Loop\LoopInterface;

final class WorkerStopState
{
    private bool $stopping = false;

    public function isStopping(): bool
    {
        return $this->stopping;
    }

    public function stop(): void
    {
        $this->stopping = true;
    }

    public function stopLoopIfStopping(LoopInterface $loop): void
    {
        if ($this->stopping) {
            $loop->stop();
        }
    }
}
