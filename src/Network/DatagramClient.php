<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Network;

use Infocyph\Runwire\Loop\LoopInterface;

/**
 * Client-facing connected and unconnected UDP API.
 *
 * Reuses the exact DatagramListener nonblocking watcher and boundary engine.
 */
final class DatagramClient
{
    private ?LoopInterface $deadlineLoop = null;

    private ?int $deadlineTimer = null;

    private function __construct(private readonly DatagramListener $socket) {}

    /** Bind an unconnected UDP client, accepting any remote peer via sendTo(). */
    public static function bind(string $address, ?DatagramOptions $options = null): self
    {
        return new self(DatagramListener::bind($address, $options));
    }

    /** Open a connected UDP client at an explicitly numeric destination. */
    public static function connect(string $remote, ?DatagramOptions $options = null): self
    {
        return new self(DatagramListener::connect($remote, $options));
    }

    /** Return local UDP endpoint metadata. */
    public function address(): string
    {
        return $this->socket->address();
    }

    /** Release the underlying socket and any loop watchers idempotently. */
    public function close(): void
    {
        if ($this->deadlineLoop !== null && $this->deadlineTimer !== null) {
            $this->deadlineLoop->cancel($this->deadlineTimer);
        }
        $this->deadlineTimer = null;
        $this->deadlineLoop = null;
        $this->socket->close();
    }

    /** Return the optional connected destination. */
    public function connectedPeer(): ?string
    {
        return $this->socket->connectedPeer();
    }

    /** Return whether this client has released its single owned UDP socket. */
    public function isClosed(): bool
    {
        return $this->socket->isClosed();
    }

    /** Return accepted datagrams, excluding truncated payloads. */
    public function receivedDatagrams(): int
    {
        return $this->socket->receivedDatagrams();
    }

    /**
     * Await one bounded UDP response or a deadline, then close owned watchers.
     *
     * @param callable(Datagram,self):void $onDatagram
     * @param callable(self):void $onTimeout
     */
    public function receiveOnce(
        LoopInterface $loop,
        float $seconds,
        callable $onDatagram,
        callable $onTimeout,
    ): void {
        if (!is_finite($seconds) || $seconds <= 0) {
            throw new \InvalidArgumentException('UDP receive deadline must be finite and positive.');
        }
        $this->start($loop, function (Datagram $packet) use ($onDatagram): void {
            $this->close();
            $onDatagram($packet, $this);
        });
        $this->deadlineLoop = $loop;
        $this->deadlineTimer = $loop->delay($seconds, function () use ($onTimeout): void {
            $this->close();
            $onTimeout($this);
        });
    }

    /** Return datagrams rejected by invalid metadata or size limits. */
    public function rejectedDatagrams(): int
    {
        return $this->socket->rejectedDatagrams();
    }

    /** Send one datagram to the connected destination. */
    public function send(string $payload): DatagramWriteResult
    {
        return $this->socket->send($payload);
    }

    /** Send one bounded datagram to a specific peer without connecting. */
    public function sendTo(string $payload, string $peer): DatagramWriteResult
    {
        return $this->socket->sendTo($payload, $peer);
    }

    /** Register one bounded receive callback on the shared datagram engine. */
    public function start(LoopInterface $loop, callable $completion): void
    {
        $this->socket->start($loop, function (Datagram $datagram) use ($completion): void {
            $completion($datagram, $this);
        });
    }
}
