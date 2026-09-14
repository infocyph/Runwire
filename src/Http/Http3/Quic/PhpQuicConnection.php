<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Http3\Quic;

use Closure;
use InvalidArgumentException;
use UnexpectedValueException;

/**
 * Wraps an ext-quic connection behind a validated Runwire-facing API.
 */
final readonly class PhpQuicConnection
{
    private Closure $acceptStreamCallback;

    private Closure $closeCallback;

    private ?Closure $handleEventsCallback;

    private Closure $negotiatedAlpnCallback;

    private Closure $openStreamCallback;

    private Closure $setBlockingCallback;

    /**
     * Wrap and validate a native ext-quic connection object.
     */
    public function __construct(private object $connection)
    {
        $this->acceptStreamCallback = self::callback($this->connection, 'acceptStream');
        $this->closeCallback = self::callback($this->connection, 'close');
        $this->handleEventsCallback = self::optionalCallback($this->connection, 'handleEvents');
        $this->negotiatedAlpnCallback = self::callback($this->connection, 'getNegotiatedAlpn');
        $this->openStreamCallback = self::callback($this->connection, 'openStream');
        $this->setBlockingCallback = self::callback($this->connection, 'setBlocking');
    }

    /**
     * Accept the next available peer stream.
     */
    public function acceptStream(): ?PhpQuicStream
    {
        $stream = ($this->acceptStreamCallback)();
        if ($stream === null) {
            return null;
        }
        if (!is_object($stream)) {
            throw new UnexpectedValueException('php-quic acceptStream() returned an invalid stream.');
        }

        return new PhpQuicStream($stream);
    }

    /**
     * Close the QUIC connection with an application error and reason.
     */
    public function close(int $errorCode = 0, string $reason = '', bool $rapid = true): void
    {
        if ($errorCode < 0) {
            throw new InvalidArgumentException('QUIC connection close error code cannot be negative.');
        }

        ($this->closeCallback)($errorCode, $reason, $rapid);
    }

    /**
     * Allow the native connection to process pending transport events.
     */
    public function handleEvents(): void
    {
        if ($this->handleEventsCallback !== null) {
            ($this->handleEventsCallback)();
        }
    }

    /**
     * Return the negotiated ALPN protocol when available.
     */
    public function negotiatedAlpn(): ?string
    {
        $alpn = ($this->negotiatedAlpnCallback)();
        if ($alpn !== null && !is_string($alpn)) {
            throw new UnexpectedValueException('php-quic returned an invalid negotiated ALPN value.');
        }

        return $alpn;
    }

    /**
     * Return the wrapped native connection object.
     */
    public function object(): object
    {
        return $this->connection;
    }

    /**
     * Open a local QUIC stream with the requested directionality.
     */
    public function openStream(bool $bidirectional): PhpQuicStream
    {
        $stream = ($this->openStreamCallback)($bidirectional);
        if (!is_object($stream)) {
            throw new UnexpectedValueException('php-quic openStream() returned an invalid stream.');
        }

        return new PhpQuicStream($stream);
    }

    /**
     * Configure the wrapped connection for non-blocking operation.
     */
    public function setNonBlocking(): void
    {
        ($this->setBlockingCallback)(false);
    }

    private static function callback(object $object, string $method): Closure
    {
        $callable = [$object, $method];
        if (!is_callable($callable)) {
            throw new InvalidArgumentException(sprintf('php-quic connection object must provide %s().', $method));
        }

        return $object->{$method}(...);
    }

    private static function optionalCallback(object $object, string $method): ?Closure
    {
        $callable = [$object, $method];
        if (!is_callable($callable)) {
            return null;
        }

        return $object->{$method}(...);
    }
}
