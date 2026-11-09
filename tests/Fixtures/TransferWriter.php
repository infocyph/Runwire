<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Tests\Fixtures;

use Infocyph\Runwire\Http\Headers;
use Infocyph\Runwire\Http\ResponseWriterInterface;
use Infocyph\Runwire\Loop\LoopInterface;
use Infocyph\Runwire\Network\Enum\WriteState;
use Infocyph\Runwire\Network\WriteResult;

/** Observable response writer used by transfer integration tests. */
final class TransferWriter implements ResponseWriterInterface
{
    public string $body = '';

    public bool $ended = false;

    public bool $started = false;

    private ?\Closure $drainCallback = null;

    /** @var list<callable(self): void> */
    private array $terminal = [];

    private int $writes = 0;

    public function __construct(private readonly LoopInterface $loop, private readonly int $pressureEvery) {}

    #[\Override]
    public function end(string $finalChunk = ''): WriteResult
    {
        if ($this->ended) {
            return new WriteResult(WriteState::CLOSED, 0);
        }
        $this->started = true;
        $this->body .= $finalChunk;
        $this->ended = true;
        foreach ($this->terminal as $callback) {
            $callback($this);
        }
        $this->terminal = [];

        return new WriteResult(WriteState::ACCEPTED, 0);
    }

    #[\Override]
    public function isEnded(): bool
    {
        return $this->ended;
    }

    #[\Override]
    public function isStarted(): bool
    {
        return $this->started;
    }

    #[\Override]
    public function onDrain(callable $callback): self
    {
        $this->drainCallback = \Closure::fromCallable($callback);

        return $this;
    }

    #[\Override]
    public function onTerminal(callable $callback): self
    {
        if ($this->ended) {
            $callback($this);

            return $this;
        }
        $this->terminal[] = $callback;

        return $this;
    }

    #[\Override]
    public function start(int $status = 200, ?Headers $headers = null): WriteResult
    {
        unset($status, $headers);
        $this->started = true;

        return new WriteResult(WriteState::ACCEPTED, 0);
    }

    #[\Override]
    public function write(string $chunk): WriteResult
    {
        if ($this->ended) {
            return new WriteResult(WriteState::CLOSED, 0);
        }
        $this->started = true;
        $this->body .= $chunk;
        ++$this->writes;
        if ($this->pressureEvery > 0 && $this->writes % $this->pressureEvery === 0) {
            $this->loop->defer(function (): void {
                $callback = $this->drainCallback;
                $this->drainCallback = null;
                if ($callback !== null) {
                    $callback($this);
                }
            });

            return new WriteResult(WriteState::PRESSURED, strlen($chunk));
        }

        return new WriteResult(WriteState::ACCEPTED, 0);
    }
}
