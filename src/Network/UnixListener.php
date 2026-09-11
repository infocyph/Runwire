<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Network;

use Closure;
use Infocyph\Runwire\Exception\ListenerException;
use Infocyph\Runwire\Loop\LoopInterface;
use LogicException;
use Throwable;

final class UnixListener
{
    private ?LoopInterface $loop = null;
    private ?Closure $connectionCallback = null;
    private ?int $acceptWatcher = null;
    private bool $acceptPaused = false;
    private bool $closed = false;
    private int $acceptedConnections = 0;
    private int $rejectedConnections = 0;
    private int $closedBytesRead = 0;
    private int $closedBytesWritten = 0;
    private ?int $socketInode = null;

    /** @var array<int, Connection> */
    private array $connections = [];

    /** @param resource $stream */
    private function __construct(
        private mixed $stream,
        private readonly string $path,
        private readonly UnixListenerOptions $options,
        private readonly ConnectionLimits $connectionLimits,
    ) {
        $inode = @fileinode($path);
        $this->socketInode = is_int($inode) ? $inode : null;
    }

    public static function bind(
        string $path,
        ?UnixListenerOptions $options = null,
        ?ConnectionLimits $connectionLimits = null,
    ): self {
        $options ??= new UnixListenerOptions();
        $connectionLimits ??= new ConnectionLimits();
        if ($path === '' || $path[0] !== '/') {
            throw new ListenerException('Unix socket path must be absolute.');
        }
        if (strlen($path) > 100) {
            throw new ListenerException('Unix socket path is too long for portable sockaddr_un usage.');
        }
        if (file_exists($path) || is_link($path)) {
            if (!$options->removeStaleSocket) {
                throw new ListenerException(sprintf('Unix socket path already exists: %s', $path));
            }
            if (@filetype($path) !== 'socket') {
                throw new ListenerException(sprintf('Refusing to remove non-socket Unix path: %s', $path));
            }
            if (!@unlink($path)) {
                throw new ListenerException(sprintf('Unable to remove existing Unix socket path: %s', $path));
            }
        }

        $context = stream_context_create([
            'socket' => [
                ...$options->listener->socketContext,
                'backlog' => $options->listener->backlog,
            ],
        ]);
        $errno = 0;
        $error = '';
        $stream = @stream_socket_server(
            'unix://' . $path,
            $errno,
            $error,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
            $context,
        );
        if (!is_resource($stream)) {
            throw new ListenerException(sprintf(
                'Unable to bind Unix listener "%s": %s (%d).',
                $path,
                $error !== '' ? $error : 'unknown error',
                $errno,
            ));
        }
        @stream_set_blocking($stream, false);
        if ($options->permissions !== null && !@chmod($path, $options->permissions)) {
            @fclose($stream);
            @unlink($path);
            throw new ListenerException(sprintf('Unable to set Unix socket permissions on "%s".', $path));
        }

        return new self($stream, $path, $options, $connectionLimits);
    }

    public function path(): string
    {
        return $this->path;
    }

    public function activeConnections(): int
    {
        return count($this->connections);
    }

    public function acceptedConnections(): int
    {
        return $this->acceptedConnections;
    }

    public function rejectedConnections(): int
    {
        return $this->rejectedConnections;
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

    public function maxConnections(): int
    {
        return $this->options->listener->maxConnections;
    }

    public function isAccepting(): bool
    {
        return !$this->closed && !$this->acceptPaused && $this->acceptWatcher !== null;
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    /** @param callable(Connection): void $onConnection */
    public function start(LoopInterface $loop, callable $onConnection): void
    {
        if ($this->closed) {
            throw new LogicException('Closed Unix listener cannot be started.');
        }
        if ($this->loop !== null) {
            throw new LogicException('Unix listener is already attached to an event loop.');
        }
        $this->loop = $loop;
        $this->connectionCallback = Closure::fromCallable($onConnection);
        $this->syncAcceptWatcher();
    }

    public function pauseAccepting(): void
    {
        $this->acceptPaused = true;
        $this->syncAcceptWatcher();
    }

    public function resumeAccepting(): void
    {
        if ($this->closed) {
            return;
        }
        $this->acceptPaused = false;
        $this->syncAcceptWatcher();
    }

    public function close(bool $unlinkPath = true): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        $this->syncAcceptWatcher();
        if (is_resource($this->stream)) {
            @fclose($this->stream);
        }
        $this->stream = null;
        $this->connectionCallback = null;
        $this->loop = null;
        if ($unlinkPath) {
            $this->unlinkOwnedPath();
        }
    }

    public function closeConnectionsGracefully(): void
    {
        foreach ($this->connections as $connection) {
            $connection->closeGracefully();
        }
    }

    public function abortConnections(): void
    {
        foreach ($this->connections as $connection) {
            $connection->abort();
        }
    }

    private function handleAccept(): void
    {
        if ($this->closed || $this->loop === null || $this->connectionCallback === null) {
            return;
        }
        for ($accepted = 0; $accepted < $this->options->listener->acceptBatchSize; ++$accepted) {
            if (count($this->connections) >= $this->options->listener->maxConnections) {
                break;
            }
            $peer = null;
            $client = @stream_socket_accept($this->stream, 0, $peer);
            if (!is_resource($client)) {
                break;
            }
            @stream_set_blocking($client, false);
            ++$this->acceptedConnections;
            $connection = new Connection(
                $this->loop,
                $client,
                $this->connectionLimits,
                is_string($peer) && $peer !== '' ? $peer : null,
                $this->path,
            );
            $id = spl_object_id($connection);
            $this->connections[$id] = $connection;
            $connection->onClose(function (Connection $closed, CloseReason $reason) use ($id): void {
                $this->closedBytesRead += $closed->bytesRead();
                $this->closedBytesWritten += $closed->bytesWritten();
                unset($this->connections[$id]);
                $this->syncAcceptWatcher();
            });

            try {
                ($this->connectionCallback)($connection);
            } catch (Throwable $failure) {
                try {
                    $connection->abort();
                } catch (Throwable) {
                    // Preserve the originating connection callback failure.
                }
                throw $failure;
            }
        }
        $this->syncAcceptWatcher();
    }

    private function syncAcceptWatcher(): void
    {
        $shouldWatch = !$this->closed
            && !$this->acceptPaused
            && $this->loop !== null
            && is_resource($this->stream)
            && count($this->connections) < $this->options->listener->maxConnections;
        if ($shouldWatch && $this->acceptWatcher === null) {
            $this->acceptWatcher = $this->loop->onReadable($this->stream, fn () => $this->handleAccept());
            return;
        }
        if (!$shouldWatch && $this->acceptWatcher !== null && $this->loop !== null) {
            $this->loop->cancel($this->acceptWatcher);
            $this->acceptWatcher = null;
        }
    }

    private function unlinkOwnedPath(): void
    {
        if (!$this->options->unlinkOnClose || !file_exists($this->path)) {
            return;
        }
        $inode = @fileinode($this->path);
        if ($this->socketInode !== null && $inode === $this->socketInode) {
            @unlink($this->path);
        }
    }
}
