<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Network;

use Closure;
use Infocyph\Runwire\Exception\ListenerException;
use Infocyph\Runwire\Loop\LoopInterface;
use LogicException;
use Throwable;

final class DatagramListener
{
    private ?LoopInterface $loop = null;
    private ?Closure $callback = null;
    private ?int $readWatcher = null;
    private bool $paused = false;
    private bool $closed = false;
    private int $receivedDatagrams = 0;
    private int $rejectedDatagrams = 0;
    private int $bytesRead = 0;
    private int $bytesWritten = 0;

    /** @param resource $stream */
    private function __construct(
        private mixed $stream,
        private readonly string $address,
        private readonly DatagramOptions $options,
    ) {
    }

    public static function bind(string $address, ?DatagramOptions $options = null): self
    {
        $options ??= new DatagramOptions();
        $uri = str_contains($address, '://') ? $address : 'udp://' . $address;
        if (!str_starts_with($uri, 'udp://')) {
            throw new ListenerException('DatagramListener accepts only udp:// addresses.');
        }

        $context = stream_context_create(['socket' => $options->socketContext]);
        $errno = 0;
        $error = '';
        $stream = @stream_socket_server($uri, $errno, $error, STREAM_SERVER_BIND, $context);
        if (!is_resource($stream)) {
            throw new ListenerException(sprintf(
                'Unable to bind UDP listener "%s": %s (%d).',
                $address,
                $error !== '' ? $error : 'unknown error',
                $errno,
            ));
        }
        @stream_set_blocking($stream, false);
        $bound = @stream_socket_get_name($stream, false);

        return new self($stream, is_string($bound) ? $bound : $address, $options);
    }

    public function address(): string
    {
        return $this->address;
    }

    public function receivedDatagrams(): int
    {
        return $this->receivedDatagrams;
    }

    public function rejectedDatagrams(): int
    {
        return $this->rejectedDatagrams;
    }

    public function bytesRead(): int
    {
        return $this->bytesRead;
    }

    public function bytesWritten(): int
    {
        return $this->bytesWritten;
    }

    public function isReceiving(): bool
    {
        return !$this->closed && !$this->paused && $this->readWatcher !== null;
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    /** @param callable(Datagram, self): void $callback */
    public function start(LoopInterface $loop, callable $callback): void
    {
        if ($this->closed) {
            throw new LogicException('Closed datagram listener cannot be started.');
        }
        if ($this->loop !== null) {
            throw new LogicException('Datagram listener is already attached to an event loop.');
        }

        $this->loop = $loop;
        $this->callback = Closure::fromCallable($callback);
        $this->syncWatcher();
    }

    public function pause(): void
    {
        $this->paused = true;
        $this->syncWatcher();
    }

    public function resume(): void
    {
        if ($this->closed) {
            return;
        }
        $this->paused = false;
        $this->syncWatcher();
    }

    public function sendTo(string $payload, string $peerAddress): DatagramWriteResult
    {
        if ($this->closed || !is_resource($this->stream)) {
            return new DatagramWriteResult(DatagramWriteState::CLOSED);
        }
        if (strlen($payload) > $this->options->maxDatagramBytes) {
            return new DatagramWriteResult(DatagramWriteState::REJECTED_LIMIT);
        }

        $written = @stream_socket_sendto($this->stream, $payload, 0, $peerAddress);
        if ($written === false) {
            return new DatagramWriteResult(DatagramWriteState::ERROR);
        }
        if ($written === 0 && $payload !== '') {
            return new DatagramWriteResult(DatagramWriteState::WOULD_BLOCK);
        }
        if ($written !== strlen($payload)) {
            return new DatagramWriteResult(DatagramWriteState::ERROR, $written);
        }
        $this->bytesWritten += $written;

        return new DatagramWriteResult(DatagramWriteState::SENT, $written);
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        $this->syncWatcher();
        if (is_resource($this->stream)) {
            @fclose($this->stream);
        }
        $this->stream = null;
        $this->callback = null;
        $this->loop = null;
    }

    private function handleReadable(): void
    {
        if ($this->closed || $this->paused || $this->callback === null || !is_resource($this->stream)) {
            return;
        }

        for ($count = 0; $count < $this->options->receiveBatchSize; ++$count) {
            $peer = null;
            $payload = @stream_socket_recvfrom($this->stream, $this->options->maxDatagramBytes + 1, 0, $peer);
            if ($payload === false) {
                break;
            }
            if (!is_string($peer) || $peer === '') {
                ++$this->rejectedDatagrams;
                continue;
            }
            if (strlen($payload) > $this->options->maxDatagramBytes) {
                ++$this->rejectedDatagrams;
                continue;
            }

            ++$this->receivedDatagrams;
            $this->bytesRead += strlen($payload);
            $local = @stream_socket_get_name($this->stream, false);
            try {
                ($this->callback)(new Datagram(
                    $payload,
                    $peer,
                    is_string($local) ? $local : null,
                ), $this);
            } catch (Throwable $failure) {
                $this->close();
                throw $failure;
            }
        }
    }

    private function syncWatcher(): void
    {
        $shouldWatch = !$this->closed && !$this->paused && $this->loop !== null && is_resource($this->stream);
        if ($shouldWatch && $this->readWatcher === null) {
            $this->readWatcher = $this->loop->onReadable($this->stream, fn () => $this->handleReadable());
            return;
        }
        if (!$shouldWatch && $this->readWatcher !== null && $this->loop !== null) {
            $this->loop->cancel($this->readWatcher);
            $this->readWatcher = null;
        }
    }
}
