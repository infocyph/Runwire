<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Supervisor;

final class WorkerContext
{
    private bool $ready = false;
    private bool $stopping = false;

    /**
     * @param resource $readyStream
     */
    public function __construct(
        public readonly string $group,
        public readonly int $slot,
        public readonly int $generation,
        public readonly int $pid,
        public readonly int $parentPid,
        private mixed $readyStream,
    ) {
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

    public function requestStop(): void
    {
        $this->stopping = true;
    }
}
