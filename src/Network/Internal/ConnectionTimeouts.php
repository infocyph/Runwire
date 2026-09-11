<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Network\Internal;

use Closure;
use Infocyph\Runwire\Loop\LoopInterface;
use Infocyph\Runwire\Network\CloseReason;

final class ConnectionTimeouts
{
    private ?int $idleTimer = null;
    private ?int $lifetimeTimer = null;
    private float $lastActivityAt;

    /** @var Closure(CloseReason): void */
    private readonly Closure $onTimeout;

    /** @param callable(CloseReason): void $onTimeout */
    public function __construct(
        private readonly LoopInterface $loop,
        private readonly ?float $idleTimeoutSeconds,
        ?float $lifetimeTimeoutSeconds,
        callable $onTimeout,
    ) {
        /** @var Closure(CloseReason): void $timeoutClosure */
        $timeoutClosure = Closure::fromCallable($onTimeout);
        $this->onTimeout = $timeoutClosure;
        $this->lastActivityAt = $loop->now();
        $this->armIdle($idleTimeoutSeconds);
        $this->armLifetime($lifetimeTimeoutSeconds);
    }

    public function touch(): void
    {
        $this->lastActivityAt = $this->loop->now();
    }

    public function cancel(): void
    {
        foreach ([$this->idleTimer, $this->lifetimeTimer] as $handle) {
            if ($handle !== null) {
                $this->loop->cancel($handle);
            }
        }
        $this->idleTimer = null;
        $this->lifetimeTimer = null;
    }

    private function armIdle(?float $delay): void
    {
        if ($delay === null) {
            return;
        }

        $this->idleTimer = $this->loop->delay($delay, function (): void {
            $this->idleTimer = null;
            $limit = $this->idleTimeoutSeconds;
            if ($limit === null) {
                return;
            }

            $remaining = $limit - ($this->loop->now() - $this->lastActivityAt);
            if ($remaining <= 0) {
                ($this->onTimeout)(CloseReason::IDLE_TIMEOUT);
                return;
            }

            $this->armIdle($remaining);
        });
    }

    private function armLifetime(?float $seconds): void
    {
        if ($seconds === null) {
            return;
        }

        $this->lifetimeTimer = $this->loop->delay($seconds, function (): void {
            $this->lifetimeTimer = null;
            ($this->onTimeout)(CloseReason::LIFETIME_TIMEOUT);
        });
    }
}
