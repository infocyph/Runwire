<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Network;

use Closure;
use Infocyph\Runwire\Exception\ListenerException;
use Infocyph\Runwire\Loop\LoopInterface;
use Infocyph\Runwire\Network\Internal\TlsHandshake;
use LogicException;
use Throwable;

final class TcpListener
{
    private int $acceptedConnections = 0;

    private bool $acceptPaused = false;

    private ?int $acceptWatcher = null;

    private bool $closed = false;

    private int $closedBytesRead = 0;

    private int $closedBytesWritten = 0;

    private ?Closure $connectionCallback = null;

    /** @var array<int, Connection> */
    private array $connections = [];

    /** @var array<int, TlsHandshake> */
    private array $handshakes = [];

    private ?LoopInterface $loop = null;

    private int $rejectedConnections = 0;

    /** @var resource|null */
    private mixed $stream;

    /** @param resource $stream */
    private function __construct(
        mixed $stream,
        private readonly string $address,
        private readonly ListenerOptions $options,
        private readonly ConnectionLimits $connectionLimits,
        private readonly ?TlsOptions $tls,
    ) {
        $this->stream = $stream;
    }

    public static function bind(
        string $address,
        ?ListenerOptions $options = null,
        ?ConnectionLimits $connectionLimits = null,
        ?TlsOptions $tls = null,
    ): self {
        $options ??= new ListenerOptions();
        $connectionLimits ??= new ConnectionLimits();
        if ($tls !== null && (!extension_loaded('openssl') || !function_exists('stream_socket_enable_crypto'))) {
            throw new ListenerException('TLS listeners require the OpenSSL extension.');
        }
        $uri = self::normalizeAddress($address);
        $contextOptions = [
            'socket' => [
                ...$options->socketContext,
                'backlog' => $options->backlog,
            ],
        ];
        if ($tls !== null) {
            $contextOptions['ssl'] = $tls->context();
        }

        $context = stream_context_create($contextOptions);
        $errno = 0;
        $error = '';
        $stream = stream_socket_server(
            $uri,
            $errno,
            $error,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
            $context,
        );
        if (!is_resource($stream)) {
            throw new ListenerException(sprintf(
                'Unable to bind TCP listener "%s": %s (%d).',
                $address,
                $error !== '' ? $error : 'unknown error',
                $errno,
            ));
        }

        if (!stream_set_blocking($stream, false)) {
            fclose($stream);

            throw new ListenerException(sprintf('Unable to make TCP listener "%s" non-blocking.', $address));
        }
        $boundAddress = stream_socket_get_name($stream, false);

        return new self(
            $stream,
            is_string($boundAddress) ? $boundAddress : $address,
            $options,
            $connectionLimits,
            $tls,
        );
    }

    public function abortConnections(): void
    {
        foreach ($this->connections as $connection) {
            $connection->abort();
        }
    }

    public function acceptedConnections(): int
    {
        return $this->acceptedConnections;
    }

    public function activeConnections(): int
    {
        return count($this->connections);
    }

    public function address(): string
    {
        return $this->address;
    }

    public function bytesRead(): int
    {
        $total = $this->closedBytesRead;
        foreach ($this->connections as $connection) {
            $total += $connection->bytesRead();
        }

        return $total;
    }

    public function bytesWritten(): int
    {
        $total = $this->closedBytesWritten;
        foreach ($this->connections as $connection) {
            $total += $connection->bytesWritten();
        }

        return $total;
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;
        $this->syncAcceptWatcher();
        foreach ($this->handshakes as $handshake) {
            $handshake->cancel();
        }
        $this->handshakes = [];
        if (is_resource($this->stream)) {
            fclose($this->stream);
        }
        $this->stream = null;
        $this->connectionCallback = null;
        $this->loop = null;
    }

    public function closeConnectionsGracefully(): void
    {
        foreach ($this->connections as $connection) {
            $connection->closeGracefully();
        }
    }

    public function isAccepting(): bool
    {
        return !$this->closed && !$this->acceptPaused && $this->acceptWatcher !== null;
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    public function maxConnections(): int
    {
        return $this->options->maxConnections;
    }

    public function pauseAccepting(): void
    {
        if ($this->closed) {
            return;
        }
        $this->acceptPaused = true;
        $this->syncAcceptWatcher();
    }

    public function pendingHandshakes(): int
    {
        return count($this->handshakes);
    }

    public function rejectedConnections(): int
    {
        return $this->rejectedConnections;
    }

    public function resumeAccepting(): void
    {
        if ($this->closed) {
            return;
        }
        $this->acceptPaused = false;
        $this->syncAcceptWatcher();
    }

    /** @param callable(Connection): void $onConnection */
    public function start(LoopInterface $loop, callable $onConnection): void
    {
        if ($this->closed) {
            throw new LogicException('Closed listener cannot be started.');
        }
        if ($this->loop !== null) {
            throw new LogicException('Listener is already attached to an event loop.');
        }

        $this->loop = $loop;
        $this->connectionCallback = Closure::fromCallable($onConnection);
        $this->syncAcceptWatcher();
    }

    private static function normalizeAddress(string $address): string
    {
        if (str_contains($address, '://')) {
            if (!str_starts_with($address, 'tcp://')) {
                throw new ListenerException('TcpListener accepts only tcp:// addresses.');
            }

            return $address;
        }

        return 'tcp://' . $address;
    }

    /** @param resource $stream */
    private function activateConnection(mixed $stream, ?string $peer, ?string $local, ?string $protocol): void
    {
        $loop = $this->loop;
        $callback = $this->connectionCallback;
        if (!is_resource($stream)) {
            ++$this->rejectedConnections;

            return;
        }
        if ($loop === null || $callback === null) {
            fclose($stream);
            ++$this->rejectedConnections;

            return;
        }

        $connection = new Connection(
            $loop,
            $stream,
            $this->connectionLimits,
            $peer,
            $local,
            $protocol,
            $this->tls !== null,
        );
        $id = spl_object_id($connection);
        $this->connections[$id] = $connection;
        $connection->onClose(function (Connection $closed) use ($id): void {
            $this->closedBytesRead += $closed->bytesRead();
            $this->closedBytesWritten += $closed->bytesWritten();
            unset($this->connections[$id]);
            $this->syncAcceptWatcher();
        });

        try {
            $callback($connection);
        } catch (Throwable $throwable) {
            try {
                $connection->abort();
            } catch (Throwable) {
                // Preserve the originating connection callback failure.
            }

            throw $throwable;
        }
    }

    private function handleAccept(): void
    {
        $loop = $this->loop;
        $callback = $this->connectionCallback;
        $listener = $this->stream;
        if ($this->closed || $loop === null || $callback === null || !is_resource($listener)) {
            return;
        }

        for ($accepted = 0; $accepted < $this->options->acceptBatchSize; ++$accepted) {
            if ($this->load() >= $this->options->maxConnections) {
                break;
            }

            $peer = null;
            $client = @stream_socket_accept($listener, 0, $peer);
            if (!is_resource($client)) {
                break;
            }

            if (!stream_set_blocking($client, false)) {
                fclose($client);
                ++$this->rejectedConnections;

                continue;
            }
            ++$this->acceptedConnections;
            $local = stream_socket_get_name($client, false);
            $peerAddress = is_string($peer) ? $peer : null;
            $localAddress = is_string($local) ? $local : null;

            if ($this->tls === null) {
                $this->activateConnection($client, $peerAddress, $localAddress, null);

                continue;
            }

            $id = get_resource_id($client);
            $this->handshakes[$id] = TlsHandshake::start(
                $loop,
                $client,
                $this->tls,
                function (mixed $stream, ?string $protocol) use ($id, $peerAddress, $localAddress): void {
                    unset($this->handshakes[$id]);
                    if (!is_resource($stream)) {
                        ++$this->rejectedConnections;
                        $this->syncAcceptWatcher();

                        return;
                    }
                    $this->activateConnection($stream, $peerAddress, $localAddress, $protocol);
                    $this->syncAcceptWatcher();
                },
                function () use ($id): void {
                    unset($this->handshakes[$id]);
                    ++$this->rejectedConnections;
                    $this->syncAcceptWatcher();
                },
            );
        }

        $this->syncAcceptWatcher();
    }

    private function load(): int
    {
        return count($this->connections) + count($this->handshakes);
    }

    private function syncAcceptWatcher(): void
    {
        $loop = $this->loop;
        $stream = $this->stream;
        $shouldWatch = !$this->closed
            && !$this->acceptPaused
            && $loop !== null
            && is_resource($stream)
            && $this->load() < $this->options->maxConnections;

        if ($shouldWatch && $this->acceptWatcher === null) {
            $this->acceptWatcher = $loop->onReadable(
                $stream,
                function (): void {
                    $this->handleAccept();
                },
            );

            return;
        }

        if (!$shouldWatch && $this->acceptWatcher !== null && $loop !== null) {
            $loop->cancel($this->acceptWatcher);
            $this->acceptWatcher = null;
        }
    }
}
