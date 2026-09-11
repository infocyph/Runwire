<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Http3\Quic;

use Closure;
use Infocyph\Runwire\Exception\ListenerException;
use Infocyph\Runwire\Http\Http3\Http3Exception;
use Infocyph\Runwire\Http\Http3\Http3Limits;
use Infocyph\Runwire\Http\HttpRequest;
use Infocyph\Runwire\Http\ResponseWriterInterface;
use InvalidArgumentException;

final class PhpQuicHttp3Worker
{
    private const float DEFAULT_POLL_TIMEOUT_SECONDS = 0.05;

    private readonly int $connectionLimit;

    /** @var Closure(HttpRequest, ResponseWriterInterface): void */
    private readonly Closure $handler;

    private readonly Http3Limits $limits;

    private readonly PhpQuicListener $listener;

    private readonly PhpQuicHttp3Poller $poller;

    private bool $accepting = true;

    /** @var array<int, PhpQuicHttp3Connection> */
    private array $connections = [];

    /** @param callable(HttpRequest, ResponseWriterInterface): void $handler */
    public function __construct(
        PhpQuicListener $listener,
        callable $handler,
        Http3Limits $limits,
        int $connectionLimit,
        ?PhpQuicHttp3Poller $poller = null,
    ) {
        if ($connectionLimit < 1 || $connectionLimit > 1_000_000) {
            throw new InvalidArgumentException('HTTP/3 worker connection limit must be between 1 and 1000000.');
        }

        /** @var Closure(HttpRequest, ResponseWriterInterface): void $handlerClosure */
        $handlerClosure = Closure::fromCallable($handler);
        $this->connectionLimit = $connectionLimit;
        $this->handler = $handlerClosure;
        $this->limits = $limits;
        $this->listener = $listener;
        $this->poller = $poller ?? new PhpQuicHttp3Poller(PhpQuicEventMasks::native());
    }

    public function accepting(): bool
    {
        return $this->accepting;
    }

    public function connectionCount(): int
    {
        return count($this->connections);
    }

    public function drainComplete(): bool
    {
        return $this->connections === [];
    }

    public function stopAccepting(): void
    {
        if (!$this->accepting) {
            return;
        }

        $this->accepting = false;
        $this->listener->close();
    }

    public function tick(?float $timeoutSeconds = self::DEFAULT_POLL_TIMEOUT_SECONDS): void
    {
        $listener = $this->accepting ? $this->listener : null;
        $canAccept = $this->accepting && $this->connectionCount() < $this->connectionLimit;
        $ready = $this->poller->poll($listener, array_values($this->connections), $canAccept, $timeoutSeconds);

        if ($listener !== null && $this->poller->listenerErrorReady($listener, $ready)) {
            throw new ListenerException('HTTP/3 QUIC listener reported a poll error.');
        }
        if ($listener !== null && $canAccept && $this->poller->listenerAcceptReady($listener, $ready)) {
            $this->acceptConnections();
        }

        foreach ($this->connections as $id => $connection) {
            try {
                $connection->handleReady($ready, $this->poller->events);
            } catch (Http3Exception) {
                // The connection owns the protocol close; one client must not terminate the worker.
            }
            if ($connection->closed()) {
                unset($this->connections[$id]);
            }
        }
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

            try {
                $session = new PhpQuicHttp3Connection($connection, $this->handler, $this->limits);
            } catch (Http3Exception $exception) {
                $connection->close($exception->errorCode->value, substr($exception->getMessage(), 0, 256), true);

                continue;
            }
            $this->connections[spl_object_id($connection->object())] = $session;
        }
    }
}
