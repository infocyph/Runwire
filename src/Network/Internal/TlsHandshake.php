<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Network\Internal;

use Closure;
use Infocyph\Runwire\Loop\LoopInterface;
use Infocyph\Runwire\Network\TlsOptions;

final class TlsHandshake
{
    private ?int $readWatcher = null;
    private ?int $timeoutTimer = null;
    private bool $finished = false;

    /**
     * @param resource $stream
     * @param callable(resource, ?string): void $onSuccess
     * @param callable(): void $onFailure
     */
    private function __construct(
        private readonly LoopInterface $loop,
        private mixed $stream,
        private readonly TlsOptions $options,
        private readonly Closure $onSuccess,
        private readonly Closure $onFailure,
    ) {
    }

    public static function start(
        LoopInterface $loop,
        mixed $stream,
        TlsOptions $options,
        callable $onSuccess,
        callable $onFailure,
    ): self {
        $handshake = new self(
            $loop,
            $stream,
            $options,
            Closure::fromCallable($onSuccess),
            Closure::fromCallable($onFailure),
        );
        $loop->defer(function () use ($handshake): void { $handshake->begin(); });
        return $handshake;
    }

    public function cancel(): void
    {
        if (!$this->finished) {
            $this->fail();
        }
    }

    private function begin(): void
    {
        if ($this->finished || !is_resource($this->stream)) {
            return;
        }
        @stream_set_blocking($this->stream, false);
        $this->timeoutTimer = $this->loop->delay($this->options->handshakeTimeoutSeconds, function (): void {
            $this->timeoutTimer = null;
            $this->fail();
        });
        $this->readWatcher = $this->loop->onReadable($this->stream, function (): void { $this->attempt(); });
    }

    private function attempt(): void
    {
        if ($this->finished || !is_resource($this->stream)) {
            return;
        }
        $result = @stream_socket_enable_crypto($this->stream, true, $this->options->method());
        if ($result === 0) {
            return;
        }
        if ($result === false) {
            $this->fail();
            return;
        }

        $meta = stream_get_meta_data($this->stream);
        $protocol = is_array($meta['crypto'] ?? null) ? ($meta['crypto']['alpn_protocol'] ?? null) : null;
        $stream = $this->stream;
        $this->finish();
        ($this->onSuccess)($stream, is_string($protocol) ? $protocol : null);
    }

    private function fail(): void
    {
        if ($this->finished) {
            return;
        }
        $stream = $this->stream;
        $this->finish();
        if (is_resource($stream)) {
            @fclose($stream);
        }
        ($this->onFailure)();
    }

    private function finish(): void
    {
        $this->finished = true;
        foreach ([$this->readWatcher, $this->timeoutTimer] as $handle) {
            if ($handle !== null) {
                $this->loop->cancel($handle);
            }
        }
        $this->readWatcher = $this->timeoutTimer = null;
        $this->stream = null;
    }
}
