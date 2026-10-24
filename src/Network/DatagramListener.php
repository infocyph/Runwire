<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Network;

use Closure;
use Infocyph\Runwire\Exception\ListenerException;
use Infocyph\Runwire\Loop\LoopInterface;
use Infocyph\Runwire\Network\Enum\DatagramWriteState;
use LogicException;
use Throwable;

/**
 * Provides bounded non-blocking UDP receive and send operations on an event loop.
 */
final class DatagramListener
{
    private int $bytesRead = 0;

    private int $bytesWritten = 0;

    private ?Closure $callback = null;

    private bool $closed = false;

    private ?LoopInterface $loop = null;

    private bool $paused = false;

    private ?int $readWatcher = null;

    private int $receivedDatagrams = 0;

    private int $rejectedDatagrams = 0;

    /** @var resource|null */
    private mixed $stream;

    /** @param resource $stream */
    private function __construct(
        mixed $stream,
        private readonly string $address,
        private readonly DatagramOptions $options,
        private readonly ?string $connectedPeer = null,
    ) {
        $this->stream = $stream;
    }

    /**
     * Bind a non-blocking UDP listener to an address.
     */
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
        $stream = stream_socket_server($uri, $errno, $error, STREAM_SERVER_BIND, $context);
        if (!is_resource($stream)) {
            throw new ListenerException(sprintf(
                'Unable to bind UDP listener "%s": %s (%d).',
                $address,
                $error !== '' ? $error : 'unknown error',
                $errno,
            ));
        }
        if (!stream_set_blocking($stream, false)) {
            fclose($stream);

            throw new ListenerException(sprintf('Unable to make UDP listener "%s" non-blocking.', $address));
        }
        $bound = stream_socket_get_name($stream, false);

        return new self($stream, is_string($bound) ? $bound : $address, $options);
    }

    /**
     * Open a nonblocking connected UDP socket using the same listener I/O engine.
     *
     * Hostnames must be resolved upstream; this method never performs blocking DNS.
     */
    public static function connect(string $address, ?DatagramOptions $options = null): self
    {
        $options ??= new DatagramOptions();
        $uri = str_contains($address, '://') ? $address : 'udp://' . $address;
        $parts = parse_url($uri);
        $host = is_array($parts) ? ($parts['host'] ?? null) : null;
        $numericHost = is_string($host) ? trim($host, '[]') : '';
        if (!is_array($parts) || ($parts['scheme'] ?? null) !== 'udp'
            || filter_var($numericHost, FILTER_VALIDATE_IP) === false
            || !isset($parts['port'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new ListenerException('Connected UDP requires a numeric IPv4/IPv6 udp://host:port endpoint.');
        }

        $context = stream_context_create(['socket' => $options->socketContext]);
        $errorCode = 0;
        $error = '';
        set_error_handler(static fn(int $severity): bool => $severity === E_WARNING);

        try {
            $stream = stream_socket_client($uri, $errorCode, $error, 0.0, STREAM_CLIENT_CONNECT, $context);
        } finally {
            restore_error_handler();
        }
        if (!is_resource($stream)) {
            throw new ListenerException(sprintf('Cannot open connected UDP socket: %s (%d).', $error, $errorCode));
        }
        if (!stream_set_blocking($stream, false)) {
            fclose($stream);

            throw new ListenerException('Connected UDP socket must be nonblocking.');
        }
        $local = stream_socket_get_name($stream, false);

        return new self($stream, is_string($local) ? $local : '', $options, $uri);
    }

    /**
     * Return the bound listener address.
     */
    public function address(): string
    {
        return $this->address;
    }

    /**
     * Return cumulative payload bytes received.
     */
    public function bytesRead(): int
    {
        return $this->bytesRead;
    }

    /**
     * Return cumulative payload bytes sent.
     */
    public function bytesWritten(): int
    {
        return $this->bytesWritten;
    }

    /**
     * Close the listener and detach it from its event loop.
     */
    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        $this->syncWatcher();
        if (is_resource($this->stream)) {
            fclose($this->stream);
        }
        $this->stream = null;
        $this->callback = null;
        $this->loop = null;
    }

    /** Return the connected peer, or null for bound unconnected sockets. */
    public function connectedPeer(): ?string
    {
        return $this->connectedPeer;
    }

    /**
     * Determine whether the listener is closed.
     */
    public function isClosed(): bool
    {
        return $this->closed;
    }

    /**
     * Determine whether datagram receive events are active.
     */
    public function isReceiving(): bool
    {
        return !$this->closed && !$this->paused && $this->readWatcher !== null;
    }

    /**
     * Pause datagram receive events.
     */
    public function pause(): void
    {
        $this->paused = true;
        $this->syncWatcher();
    }

    /**
     * Return the cumulative accepted datagram count.
     */
    public function receivedDatagrams(): int
    {
        return $this->receivedDatagrams;
    }

    /**
     * Return the cumulative rejected datagram count.
     */
    public function rejectedDatagrams(): int
    {
        return $this->rejectedDatagrams;
    }

    /**
     * Resume datagram receive events.
     */
    public function resume(): void
    {
        if ($this->closed) {
            return;
        }
        $this->paused = false;
        $this->syncWatcher();
    }

    /** Send one bounded datagram to the connected remote peer. */
    public function send(string $payload): DatagramWriteResult
    {
        if ($this->connectedPeer === null) {
            throw new LogicException('Unconnected UDP sockets require sendTo().');
        }

        return $this->sendTo($payload, substr($this->connectedPeer, 6));
    }

    /**
     * Send one datagram to a peer address.
     */
    public function sendTo(string $payload, string $peerAddress): DatagramWriteResult
    {
        $stream = $this->stream;
        if ($this->closed || !is_resource($stream)) {
            return new DatagramWriteResult(DatagramWriteState::CLOSED);
        }
        if (strlen($payload) > $this->options->maxDatagramBytes) {
            return new DatagramWriteResult(DatagramWriteState::REJECTED_LIMIT);
        }

        $written = stream_socket_sendto($stream, $payload, 0, $peerAddress);
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

    /**
     * @param Closure(Datagram, self): void $callback
     * @param resource $stream
     */
    private function dispatchDatagram(Closure $callback, Datagram $datagram, mixed $stream): bool
    {
        try {
            $callback($datagram, $this);
        } catch (Throwable $failure) {
            $this->close();

            throw $failure;
        }

        return $this->closed || $this->paused || $this->stream !== $stream;
    }

    private function handleReadable(): void
    {
        $callback = $this->callback;
        $stream = $this->stream;
        if ($this->closed || $this->paused || $callback === null || !is_resource($stream)) {
            return;
        }

        for ($count = 0; $count < $this->options->receiveBatchSize; ++$count) {
            $peer = null;
            $payload = stream_socket_recvfrom($stream, $this->options->maxDatagramBytes + 1, 0, $peer);
            if ($payload === false) {
                break;
            }
            $datagram = $this->receivedDatagram($stream, $peer, $payload);
            if ($datagram === null) {
                continue;
            }

            if ($this->dispatchDatagram(
                $callback,
                $datagram,
                $stream,
            )) {
                break;
            }
        }
    }

    /** @param resource $stream */
    private function receivedDatagram(mixed $stream, mixed $peer, string $payload): ?Datagram
    {
        if (!is_string($peer) || $peer === '' || strlen($payload) > $this->options->maxDatagramBytes) {
            ++$this->rejectedDatagrams;

            return null;
        }
        ++$this->receivedDatagrams;
        $this->bytesRead += strlen($payload);
        $local = stream_socket_get_name($stream, false);

        return new Datagram($payload, $peer, is_string($local) ? $local : null);
    }

    private function syncWatcher(): void
    {
        $loop = $this->loop;
        $stream = $this->stream;
        $shouldWatch = !$this->closed && !$this->paused && $loop !== null && is_resource($stream);
        if ($shouldWatch && $this->readWatcher === null) {
            $this->readWatcher = $loop->onReadable($stream, fn() => $this->handleReadable());

            return;
        }
        if (!$shouldWatch && $this->readWatcher !== null && $loop !== null) {
            $loop->cancel($this->readWatcher);
            $this->readWatcher = null;
        }
    }
}
