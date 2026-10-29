<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Network\Internal;

use Infocyph\Runwire\Loop\LoopInterface;
use Throwable;

/** @internal Shared ownership of nonblocking TLS readiness and deadline handles. */
final class TlsHandshakeReadiness
{
    private ?int $readWatcher = null;

    private ?int $timeoutTimer = null;

    private ?int $writeWatcher = null;

    /** Create a shared watch owner for a single event-loop lifetime. */
    public function __construct(private readonly LoopInterface $loop) {}

    /** Cancel all registered watches and timeout handles exactly once. */
    public function close(): void
    {
        foreach ([$this->readWatcher, $this->writeWatcher, $this->timeoutTimer] as $handle) {
            if ($handle !== null) {
                $this->loop->cancel($handle);
            }
        }
        $this->readWatcher = $this->writeWatcher = $this->timeoutTimer = null;
    }

    /**
     * @param resource $stream
     * @param callable():void $onReady
     * @param callable():void $onTimeout
     */
    public function start(
        mixed $stream,
        float $seconds,
        callable $onReady,
        callable $onTimeout,
        bool $watchWrite,
    ): void {
        try {
            $this->timeoutTimer = $this->loop->delay($seconds, $onTimeout);
            $this->readWatcher = $this->loop->onReadable($stream, $onReady);
            if ($watchWrite) {
                $this->writeWatcher = $this->loop->onWritable($stream, $onReady);
            }
        } catch (Throwable $error) {
            $this->close();

            throw $error;
        }
    }
}
