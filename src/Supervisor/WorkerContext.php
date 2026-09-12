<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Supervisor;

use Infocyph\Runwire\Runtime\Internal\WorkerRecycleState;
use RuntimeException;

final class WorkerContext
{
    private readonly WorkerRecycleState $recycleState;

    private bool $ready = false;

    /** @var resource|null */
    private mixed $readyStream;

    private bool $recycling = false;

    private bool $stopping = false;

    /** @var resource|null */
    private mixed $stopRead = null;

    /** @var resource|null */
    private mixed $stopWrite = null;

    /**
     * @param resource $readyStream
     */
    public function __construct(
        public readonly string $group,
        public readonly int $slot,
        public readonly int $generation,
        public readonly int $pid,
        public readonly int $parentPid,
        mixed $readyStream,
        public readonly WorkerRecyclePolicy $recyclePolicy = new WorkerRecyclePolicy(),
    ) {
        $this->readyStream = $readyStream;
        $this->recycleState = new WorkerRecycleState(
            $this->recyclePolicy,
            seed: $pid ^ ($slot << 8) ^ ($generation << 16),
        );
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if (!is_array($pair) || count($pair) !== 2) {
            throw new RuntimeException('Unable to create worker stop wake channel.');
        }

        [$this->stopRead, $this->stopWrite] = $pair;
        if (!stream_set_blocking($this->stopRead, false) || !stream_set_blocking($this->stopWrite, false)) {
            $this->close();

            throw new RuntimeException('Unable to configure worker stop wake channel.');
        }
    }

    public function close(): void
    {
        foreach (['readyStream', 'stopRead', 'stopWrite'] as $property) {
            if (is_resource($this->{$property})) {
                fclose($this->{$property});
            }
            $this->{$property} = null;
        }
    }

    public function consumeStopWake(): void
    {
        if (!is_resource($this->stopRead)) {
            return;
        }

        do {
            $chunk = fread($this->stopRead, 8_192);
        } while (is_string($chunk) && $chunk !== '');
    }

    public function currentMemoryBytes(): int
    {
        return $this->recycleState->currentMemoryBytes();
    }

    public function effectiveMaxLifetimeSeconds(): int
    {
        return $this->recycleState->effectiveMaxLifetimeSeconds();
    }

    public function effectiveMaxRequests(): int
    {
        return $this->recycleState->effectiveMaxRequests();
    }

    public function peakMemoryBytes(): int
    {
        return $this->recycleState->peakMemoryBytes();
    }

    public function ready(): void
    {
        if ($this->ready) {
            return;
        }

        if (is_resource($this->readyStream)) {
            $written = fwrite($this->readyStream, 'R');
            if ($written !== 1) {
                throw new RuntimeException('Unable to signal worker readiness.');
            }
            fclose($this->readyStream);
        }

        $this->readyStream = null;
        $this->ready = true;
    }

    public function recordRequestCompleted(): bool
    {
        $recycle = $this->recycleState->recordRequestCompleted();
        if ($recycle) {
            $this->requestRecycle();
        }

        return $recycle;
    }

    public function recycling(): bool
    {
        return $this->recycling;
    }

    public function requestRecycle(): void
    {
        if ($this->recycling) {
            return;
        }

        $this->recycling = true;
        $this->requestStop();
    }

    public function requestStop(): void
    {
        if ($this->stopping) {
            return;
        }

        $this->stopping = true;
        if (is_resource($this->stopWrite)) {
            fwrite($this->stopWrite, 'S');
        }
    }

    public function requestsTotal(): int
    {
        return $this->recycleState->requestsTotal();
    }

    public function stopping(): bool
    {
        return $this->stopping;
    }

    /** @return resource */
    public function stopStream(): mixed
    {
        if (!is_resource($this->stopRead)) {
            throw new RuntimeException('Worker stop wake channel is closed.');
        }

        return $this->stopRead;
    }
}
