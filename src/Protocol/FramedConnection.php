<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Protocol;

use Closure;
use Infocyph\Runwire\Loop\LoopInterface;
use Infocyph\Runwire\Network\Connection;
use Infocyph\Runwire\Network\Enum\CloseReason;
use Infocyph\Runwire\Network\Enum\ConnectionState;
use Infocyph\Runwire\Network\WriteResult;
use InvalidArgumentException;
use Throwable;

final class FramedConnection
{
    private readonly Closure $frameHandler;

    private bool $pumping = false;

    private bool $pumpScheduled = false;

    /** @param callable(string, self): void $onFrame */
    public function __construct(
        private readonly LoopInterface $loop,
        private readonly Connection $connection,
        private readonly FrameCodecInterface $codec,
        callable $onFrame,
        private readonly int $maxFramesPerTick = 256,
    ) {
        if ($maxFramesPerTick <= 0 || $maxFramesPerTick > 65_536) {
            throw new InvalidArgumentException('Maximum frames per tick must be between 1 and 65536.');
        }
        $this->frameHandler = Closure::fromCallable($onFrame);
        $this->connection->onData(function (): void {
            $bytes = $this->connection->read();
            if ($bytes !== '') {
                $this->decodeAndDispatch($bytes);
            }
        });
        $this->connection->onClose(function (): void {
            $this->codec->reset();
        });
    }

    public function abort(CloseReason $reason = CloseReason::LOCAL_ABORT): void
    {
        $this->connection->abort($reason);
    }

    public function closeGracefully(): void
    {
        $this->connection->closeGracefully();
    }

    public function codec(): FrameCodecInterface
    {
        return $this->codec;
    }

    /** @param callable(self): void $callback */
    public function onDrain(callable $callback): self
    {
        $consumer = Closure::fromCallable($callback);
        $this->connection->onDrain(function () use ($consumer): void {
            $consumer($this);
        });

        return $this;
    }

    public function send(string $frame): WriteResult
    {
        return $this->connection->write($this->codec->encode($frame));
    }

    public function transport(): Connection
    {
        return $this->connection;
    }

    private function decodeAndDispatch(string $bytes): void
    {
        try {
            $frames = $this->codec->push($bytes, $this->maxFramesPerTick);
            if (count($frames) > $this->maxFramesPerTick) {
                throw new CodecException('Frame codec exceeded the requested per-tick decode limit.');
            }
        } catch (CodecException) {
            $this->connection->abort(CloseReason::PROTOCOL_ERROR);

            return;
        } catch (Throwable $failure) {
            $this->connection->abort(CloseReason::PROTOCOL_ERROR);

            throw $failure;
        }

        $this->dispatch($frames);
        if (count($frames) === $this->maxFramesPerTick) {
            $this->scheduleIfMoreMayBeReady();
        }
    }

    /** @param list<string> $frames */
    private function dispatch(array $frames): void
    {
        if ($this->pumping) {
            return;
        }
        $this->pumping = true;

        try {
            foreach ($frames as $frame) {
                if ($this->connection->state() === ConnectionState::CLOSED) {
                    break;
                }
                ($this->frameHandler)($frame, $this);
            }
        } catch (Throwable $failure) {
            try {
                $this->connection->abort(CloseReason::LOCAL_ABORT);
            } catch (Throwable) {
                // Preserve the originating frame-handler failure.
            }

            throw $failure;
        } finally {
            $this->pumping = false;
        }
    }

    private function scheduleIfMoreMayBeReady(): void
    {
        if ($this->pumpScheduled || $this->connection->state() === ConnectionState::CLOSED) {
            return;
        }
        $this->pumpScheduled = true;
        $this->loop->defer(function (): void {
            $this->pumpScheduled = false;
            if ($this->connection->state() === ConnectionState::CLOSED) {
                return;
            }
            $this->decodeAndDispatch('');
        });
    }
}
