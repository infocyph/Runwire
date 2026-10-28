<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Supervisor\Internal;

use Closure;
use Infocyph\Runwire\Loop\LoopInterface;

/**
 * Reports supervisor busy/idle transitions without flooding a nonblocking
 * lifecycle channel with two frames per short request.
 */
final class WorkerActivitySignals
{
    private const float IDLE_QUIET_SECONDS = 0.05;

    private int $active = 0;

    private bool $busy = false;

    private int $epoch = 0;

    private ?LoopInterface $loop = null;

    private bool $stopping = false;

    private ?int $timer = null;

    /** @param Closure(string): void $signal */
    public function __construct(private readonly Closure $signal) {}

    public function attach(LoopInterface $loop): void
    {
        $this->loop = $loop;
    }

    public function close(): void
    {
        $this->stopping = true;
        $id = $this->timer;
        $loop = $this->loop;
        if ($id !== null && $loop !== null) {
            // Preserve the handle if cancellation throws so close() can retry.
            $loop->cancel($id);
        }
        $this->timer = null;
        $this->loop = null;
    }

    public function completed(): void
    {
        if ($this->active === 0) {
            return;
        }
        --$this->active;
        if ($this->active === 0 && !$this->stopping && $this->busy) {
            $this->reportIdle();
        }
    }

    public function started(): void
    {
        ++$this->active;
        ++$this->epoch;
        if ($this->active === 1 && !$this->stopping && !$this->busy) {
            ($this->signal)('B');
            $this->busy = true;
        }
    }

    private function reportIdle(): void
    {
        $loop = $this->loop;
        if ($loop === null) {
            ($this->signal)('I');
            $this->busy = false;

            return;
        }
        if ($this->timer !== null) {
            return;
        }

        $lastEpoch = $this->epoch;
        $this->timer = $loop->repeat(self::IDLE_QUIET_SECONDS, function () use ($loop, &$lastEpoch): void {
            if (!$this->stopping && $this->busy && $this->active === 0 && $lastEpoch === $this->epoch) {
                ($this->signal)('I');
                $this->busy = false;
            } else {
                $lastEpoch = $this->epoch;
            }
            if ($this->stopping || !$this->busy) {
                $id = $this->timer;
                if ($id !== null) {
                    $loop->cancel($id);
                }
                $this->timer = null;
            }
        });
    }
}
