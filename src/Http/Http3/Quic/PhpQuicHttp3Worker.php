<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Http3\Quic;

use Closure;
use Infocyph\Runwire\Exception\ListenerException;
use Infocyph\Runwire\Http\Http3\Enum\ErrorCode;
use Infocyph\Runwire\Http\Http3\Http3Exception;
use Infocyph\Runwire\Http\Http3\Http3Limits;
use Infocyph\Runwire\Http\HttpRequest;
use Infocyph\Runwire\Http\ResponseWriterInterface;
use InvalidArgumentException;
use Throwable;

final class PhpQuicHttp3Worker
{
    private const float DEFAULT_HANDSHAKE_TIMEOUT_SECONDS = 10.0;

    private const float DEFAULT_POLL_TIMEOUT_SECONDS = 0.05;

    private readonly int $connectionLimit;

    /** @var Closure(HttpRequest, ResponseWriterInterface): void */
    private readonly Closure $handler;

    private readonly PhpQuicHttp3Poller $poller;

    private bool $accepting = true;

    /** @var array<int, PhpQuicHttp3Connection> */
    private array $connections = [];

    /** @var array<int, array{connection: PhpQuicConnection, deadline: float}> */
    private array $pendingConnections = [];

    /** @param callable(HttpRequest, ResponseWriterInterface): void $handler */
    public function __construct(
        private readonly PhpQuicListener $listener,
        callable $handler,
        private readonly Http3Limits $limits,
        int $connectionLimit,
        ?PhpQuicHttp3Poller $poller = null,
        private readonly float $handshakeTimeoutSeconds = self::DEFAULT_HANDSHAKE_TIMEOUT_SECONDS,
    ) {
        if ($connectionLimit < 1 || $connectionLimit > 1_000_000) {
            throw new InvalidArgumentException('HTTP/3 worker connection limit must be between 1 and 1000000.');
        }
        if (!is_finite($handshakeTimeoutSeconds) || $handshakeTimeoutSeconds <= 0 || $handshakeTimeoutSeconds > 60.0) {
            throw new InvalidArgumentException('HTTP/3 handshake timeout must be finite and between 0 and 60 seconds.');
        }

        /** @var Closure(HttpRequest, ResponseWriterInterface): void $handlerClosure */
        $handlerClosure = Closure::fromCallable($handler);
        $this->connectionLimit = $connectionLimit;
        $this->handler = $handlerClosure;
        $this->poller = $poller ?? new PhpQuicHttp3Poller(PhpQuicEventMasks::native());
    }

    public function accepting(): bool
    {
        return $this->accepting;
    }

    public function connectionCount(): int
    {
        return count($this->pendingConnections) + count($this->connections);
    }

    public function drainComplete(): bool
    {
        return $this->pendingConnections === [] && $this->connections === [];
    }

    public function forceClose(): void
    {
        $this->stopAccepting();
        foreach ($this->pendingConnections as $id => $pending) {
            self::closeConnection($pending['connection'], ErrorCode::NO_ERROR->value, 'Worker recycle drain timeout.');
            unset($this->pendingConnections[$id]);
        }
        foreach ($this->connections as $id => $connection) {
            self::closeConnection($connection->connection(), ErrorCode::NO_ERROR->value, 'Worker recycle drain timeout.');
            unset($this->connections[$id]);
        }
    }

    public function stopAccepting(): void
    {
        if (!$this->accepting) {
            return;
        }

        $this->accepting = false;
        $this->listener->close();
        foreach ($this->connections as $connection) {
            $connection->beginDrain();
        }
    }

    public function tick(?float $timeoutSeconds = self::DEFAULT_POLL_TIMEOUT_SECONDS): void
    {
        $listener = $this->accepting ? $this->listener : null;
        $canAccept = $this->accepting && $this->connectionCount() < $this->connectionLimit;
        $ready = $this->poller->poll(
            $listener,
            array_values($this->connections),
            $canAccept,
            $timeoutSeconds,
            array_map(
                static fn(array $pending): PhpQuicConnection => $pending['connection'],
                array_values($this->pendingConnections),
            ),
        );

        if ($listener !== null && $this->poller->listenerErrorReady($listener, $ready)) {
            throw new ListenerException('HTTP/3 QUIC listener reported a poll error.');
        }
        if ($listener !== null && $canAccept && $this->poller->listenerAcceptReady($listener, $ready)) {
            $this->acceptConnections();
        }

        $this->pumpTransportEvents($listener);
        $this->promotePendingConnections($ready);
        $this->handleActiveConnections($ready);
    }

    private static function closeConnection(PhpQuicConnection $connection, int $errorCode, string $reason): void
    {
        try {
            $connection->close($errorCode, substr($reason, 0, 256), true);
        } catch (Throwable) {
            // Releasing the wrapper still frees a failed native transport without blocking the worker.
        }
    }

    private static function monotonicSeconds(): float
    {
        return hrtime(true) / 1_000_000_000;
    }

    private function acceptConnections(): void
    {
        $remaining = $this->connectionLimit - $this->connectionCount();
        $limit = min($remaining, $this->limits->maxConnectionsAcceptedPerPump);
        for ($accepted = 0; $accepted < $limit; ++$accepted) {
            $connection = $this->listener->accept();
            if ($connection === null) {
                return;
            }

            $this->pendingConnections[spl_object_id($connection->object())] = [
                'connection' => $connection,
                'deadline' => self::monotonicSeconds() + $this->handshakeTimeoutSeconds,
            ];
        }
    }

    /** @param array<int, int> $ready */
    private function handleActiveConnections(array $ready): void
    {
        foreach ($this->connections as $id => $connection) {
            try {
                $connection->handleReady($ready, $this->poller->events);
            } catch (Http3Exception) {
                // The connection owns the protocol close; one client must not terminate the worker.
            } catch (Throwable) {
                unset($this->connections[$id]);

                continue;
            }
            if ($connection->closed()) {
                unset($this->connections[$id]);
            }
        }
    }

    /** @param array<int, int> $ready */
    private function pendingConnectionErrored(PhpQuicConnection $connection, array $ready): bool
    {
        return (($ready[spl_object_id($connection->object())] ?? 0) & $this->poller->events->error) !== 0;
    }

    /** @param array<int, int> $ready */
    private function promotePendingConnections(array $ready): void
    {
        $now = self::monotonicSeconds();
        foreach ($this->pendingConnections as $id => $pending) {
            $connection = $pending['connection'];
            if ($this->pendingConnectionErrored($connection, $ready)) {
                unset($this->pendingConnections[$id]);

                continue;
            }
            if ($now >= $pending['deadline']) {
                self::closeConnection($connection, 0, 'QUIC handshake timeout.');
                unset($this->pendingConnections[$id]);

                continue;
            }

            try {
                $alpn = $connection->negotiatedAlpn();
            } catch (Throwable) {
                unset($this->pendingConnections[$id]);

                continue;
            }
            if ($alpn === null) {
                continue;
            }
            unset($this->pendingConnections[$id]);
            if ($alpn !== 'h3') {
                self::closeConnection($connection, ErrorCode::GENERAL_PROTOCOL_ERROR->value, 'QUIC connection did not negotiate h3.');

                continue;
            }

            try {
                $http3 = new PhpQuicHttp3Connection($connection, $this->handler, $this->limits);
                if (!$this->accepting) {
                    $http3->beginDrain();
                }
                $this->connections[$id] = $http3;
            } catch (Http3Exception $exception) {
                self::closeConnection($connection, $exception->errorCode->value, $exception->getMessage());
            } catch (Throwable $exception) {
                self::closeConnection($connection, ErrorCode::INTERNAL_ERROR->value, $exception->getMessage());
            }
        }
    }

    private function pumpTransportEvents(?PhpQuicListener $listener): void
    {
        if ($listener !== null) {
            $listener->handleEvents();
        }

        foreach ($this->pendingConnections as $id => $pending) {
            try {
                $pending['connection']->handleEvents();
            } catch (Throwable) {
                unset($this->pendingConnections[$id]);
            }
        }
        foreach ($this->connections as $id => $connection) {
            try {
                $connection->connection()->handleEvents();
            } catch (Throwable) {
                unset($this->connections[$id]);
            }
        }
    }
}
