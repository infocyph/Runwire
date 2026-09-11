<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Supervisor;

use RuntimeException;

final class WorkerContext
{
    private bool $ready = false;
    private bool $stopping = false;

    /** @var resource|null */
    private mixed $readyStream;

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
    ) {
        $this->readyStream = $readyStream;
        $pair = @stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if (!is_array($pair) || count($pair) !== 2) {
            throw new RuntimeException('Unable to create worker stop wake channel.');
        }

        [$this->stopRead, $this->stopWrite] = $pair;
        @stream_set_blocking($this->stopRead, false);
        @stream_set_blocking($this->stopWrite, false);
    }

    public function ready(): void
    {
        if ($this->ready) {
            return;
        }

        $this->ready = true;

        if (is_resource($this->readyStream)) {
            @fwrite($this->readyStream, "R");
            @fclose($this->readyStream);
        }

        $this->readyStream = null;
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

    public function consumeStopWake(): void
    {
        if (!is_resource($this->stopRead)) {
            return;
        }

        while (is_string($chunk = @fread($this->stopRead, 8_192)) && $chunk !== '') {
            // Drain all pending wake bytes before returning to normal control flow.
        }
    }

    public function requestStop(): void
    {
        if ($this->stopping) {
            return;
        }

        $this->stopping = true;
        if (is_resource($this->stopWrite)) {
            @fwrite($this->stopWrite, "S");
        }
    }

    public function close(): void
    {
        foreach (['readyStream', 'stopRead', 'stopWrite'] as $property) {
            if (is_resource($this->{$property})) {
                @fclose($this->{$property});
            }
            $this->{$property} = null;
        }
    }
}
