<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Network;

use Closure;
use Infocyph\Runwire\Exception\ListenerException;
use Infocyph\Runwire\Loop\LoopInterface;
use LogicException;
use Throwable;

/**
 * Accepts non-blocking Unix-domain stream connections on a filesystem socket.
 */
final class UnixListener
{
    private readonly ?int $socketInode;

    private int $acceptedConnections = 0;

    private bool $acceptPaused = false;

    private ?int $acceptWatcher = null;

    private bool $closed = false;

    private int $closedBytesRead = 0;

    private int $closedBytesWritten = 0;

    private ?Closure $connectionCallback = null;

    /** @var array<int, Connection> */
    private array $connections = [];

    private ?LoopInterface $loop = null;

    private int $rejectedConnections = 0;

    /** @var resource|null */
    private mixed $stream;

    /** @param resource $stream */
    private function __construct(
        mixed $stream,
        private readonly string $path,
        private readonly UnixListenerOptions $options,
        private readonly ConnectionLimits $connectionLimits,
    ) {
        $this->stream = $stream;
        $inode = fileinode($path);
        $this->socketInode = is_int($inode) ? $inode : null;
    }

    /**
     * Binds a Unix-domain listener at the supplied absolute socket path.
     */
    public static function bind(
        string $path,
        ?UnixListenerOptions $options = null,
        ?ConnectionLimits $connectionLimits = null,
    ): self {
        $options ??= new UnixListenerOptions();
        $connectionLimits ??= new ConnectionLimits();

        self::validatePath($path);
        self::preparePath($path, $options);
        $stream = self::openListener($path, $options);
        self::configureListener($stream, $path, $options);

        return new self($stream, $path, $options, $connectionLimits);
    }

    /**
     * Immediately aborts every active connection.
     */
    public function abortConnections(): void
    {
        foreach ($this->connections as $connection) {
            $connection->abort();
        }
    }

    /**
     * Returns the total number of accepted client connections.
     */
    public function acceptedConnections(): int
    {
        return $this->acceptedConnections;
    }

    /**
     * Returns the number of currently active connections.
     */
    public function activeConnections(): int
    {
        return count($this->connections);
    }

    /**
     * Returns cumulative bytes read across active and closed connections.
     */
    public function bytesRead(): int
    {
        $total = $this->closedBytesRead;
        foreach ($this->connections as $connection) {
            $total += $connection->bytesRead();
        }

        return $total;
    }

    /**
     * Returns cumulative bytes written across active and closed connections.
     */
    public function bytesWritten(): int
    {
        $total = $this->closedBytesWritten;
        foreach ($this->connections as $connection) {
            $total += $connection->bytesWritten();
        }

        return $total;
    }

    /**
     * Closes the listener and optionally unlinks its owned socket path.
     */
    public function close(bool $unlinkPath = true): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        $this->syncAcceptWatcher();
        if (is_resource($this->stream)) {
            fclose($this->stream);
        }
        $this->stream = null;
        $this->connectionCallback = null;
        $this->loop = null;
        if ($unlinkPath) {
            $this->unlinkOwnedPath();
        }
    }

    /**
     * Begins graceful closure of all active connections.
     */
    public function closeConnectionsGracefully(): void
    {
        foreach ($this->connections as $connection) {
            $connection->closeGracefully();
        }
    }

    /**
     * Reports whether the listener is currently accepting connections.
     */
    public function isAccepting(): bool
    {
        return !$this->closed && !$this->acceptPaused && $this->acceptWatcher !== null;
    }

    /**
     * Reports whether the listener has been closed.
     */
    public function isClosed(): bool
    {
        return $this->closed;
    }

    /**
     * Returns the configured concurrent connection ceiling.
     */
    public function maxConnections(): int
    {
        return $this->options->listener->maxConnections;
    }

    /**
     * Returns the bound Unix-domain socket path.
     */
    public function path(): string
    {
        return $this->path;
    }

    /**
     * Temporarily pauses accepting new connections.
     */
    public function pauseAccepting(): void
    {
        $this->acceptPaused = true;
        $this->syncAcceptWatcher();
    }

    /**
     * Returns the total number of rejected connection attempts.
     */
    public function rejectedConnections(): int
    {
        return $this->rejectedConnections;
    }

    /**
     * Resumes accepting connections after a pause.
     */
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
            throw new LogicException('Closed Unix listener cannot be started.');
        }
        if ($this->loop !== null) {
            throw new LogicException('Unix listener is already attached to an event loop.');
        }
        $this->loop = $loop;
        $this->connectionCallback = Closure::fromCallable($onConnection);
        $this->syncAcceptWatcher();
    }

    /** @param resource $stream */
    private static function configureListener(mixed $stream, string $path, UnixListenerOptions $options): void
    {
        if (!stream_set_blocking($stream, false)) {
            self::discardListener($stream, $path);

            throw new ListenerException(sprintf('Unable to make Unix listener "%s" non-blocking.', $path));
        }
        if ($options->permissions !== null && !chmod($path, $options->permissions)) {
            self::discardListener($stream, $path);

            throw new ListenerException(sprintf('Unable to set Unix socket permissions on "%s".', $path));
        }
    }

    /** @param resource $stream */
    private static function discardListener(mixed $stream, string $path): void
    {
        fclose($stream);
        if (file_exists($path)) {
            unlink($path);
        }
    }

    private static function ignoreAcceptWarning(int $severity, string $message): bool
    {
        return $severity === E_WARNING
            && str_starts_with($message, 'stream_socket_accept(): Accept failed:');
    }

    /** @return resource */
    private static function openListener(string $path, UnixListenerOptions $options): mixed
    {
        $context = stream_context_create([
            'socket' => [
                ...$options->listener->socketContext,
                'backlog' => $options->listener->backlog,
            ],
        ]);
        $errno = 0;
        $error = '';
        $stream = stream_socket_server(
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

        return $stream;
    }

    private static function preparePath(string $path, UnixListenerOptions $options): void
    {
        if (!file_exists($path) && !is_link($path)) {
            return;
        }
        if (!$options->removeStaleSocket) {
            throw new ListenerException(sprintf('Unix socket path already exists: %s', $path));
        }
        if (filetype($path) !== 'socket') {
            throw new ListenerException(sprintf('Refusing to remove non-socket Unix path: %s', $path));
        }

        $errno = 0;
        $error = '';
        $live = @stream_socket_client(
            'unix://' . $path,
            $errno,
            $error,
            0.1,
            STREAM_CLIENT_CONNECT,
        );
        if (is_resource($live)) {
            fclose($live);

            throw new ListenerException(sprintf('Refusing to replace live Unix socket path: %s', $path));
        }

        if (!unlink($path)) {
            throw new ListenerException(sprintf('Unable to remove existing Unix socket path: %s', $path));
        }
    }

    private static function validatePath(string $path): void
    {
        if ($path === '' || $path[0] !== '/') {
            throw new ListenerException('Unix socket path must be absolute.');
        }
        if (strlen($path) > 100) {
            throw new ListenerException('Unix socket path is too long for portable sockaddr_un usage.');
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
        for ($accepted = 0; $accepted < $this->options->listener->acceptBatchSize; ++$accepted) {
            if (count($this->connections) >= $this->options->listener->maxConnections) {
                break;
            }
            $peer = null;
            set_error_handler(self::ignoreAcceptWarning(...));

            try {
                $client = stream_socket_accept($listener, 0, $peer);
            } finally {
                restore_error_handler();
            }

            if (!is_resource($client)) {
                break;
            }
            if (!stream_set_blocking($client, false)) {
                fclose($client);
                ++$this->rejectedConnections;

                continue;
            }
            ++$this->acceptedConnections;
            $connection = new Connection(
                $loop,
                $client,
                $this->connectionLimits,
                is_string($peer) && $peer !== '' ? $peer : null,
                $this->path,
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
        $loop = $this->loop;
        $stream = $this->stream;
        $shouldWatch = !$this->closed
            && !$this->acceptPaused
            && $loop !== null
            && is_resource($stream)
            && count($this->connections) < $this->options->listener->maxConnections;
        if ($shouldWatch && $this->acceptWatcher === null) {
            $this->acceptWatcher = $loop->onReadable($stream, fn() => $this->handleAccept());

            return;
        }
        if (!$shouldWatch && $this->acceptWatcher !== null && $loop !== null) {
            $loop->cancel($this->acceptWatcher);
            $this->acceptWatcher = null;
        }
    }

    private function unlinkOwnedPath(): void
    {
        if (!$this->options->unlinkOnClose || !file_exists($this->path)) {
            return;
        }
        $inode = fileinode($this->path);
        if ($this->socketInode !== null && $inode === $this->socketInode) {
            unlink($this->path);
        }
    }
}
