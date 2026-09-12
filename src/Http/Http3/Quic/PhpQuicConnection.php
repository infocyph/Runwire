<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Http3\Quic;

use Closure;
use InvalidArgumentException;
use UnexpectedValueException;

final readonly class PhpQuicConnection
{
    private Closure $acceptStreamCallback;

    private Closure $closeCallback;

    private ?Closure $handleEventsCallback;

    private Closure $negotiatedAlpnCallback;

    private Closure $openStreamCallback;

    private Closure $setBlockingCallback;

    public function __construct(private object $connection)
    {
        $this->acceptStreamCallback = self::callback($this->connection, 'acceptStream');
        $this->closeCallback = self::callback($this->connection, 'close');
        $this->handleEventsCallback = self::optionalCallback($this->connection, 'handleEvents');
        $this->negotiatedAlpnCallback = self::callback($this->connection, 'getNegotiatedAlpn');
        $this->openStreamCallback = self::callback($this->connection, 'openStream');
        $this->setBlockingCallback = self::callback($this->connection, 'setBlocking');
    }

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

    public function close(int $errorCode = 0, string $reason = '', bool $rapid = true): void
    {
        if ($errorCode < 0) {
            throw new InvalidArgumentException('QUIC connection close error code cannot be negative.');
        }

        ($this->closeCallback)($errorCode, $reason, $rapid);
    }

    public function handleEvents(): void
    {
        if ($this->handleEventsCallback !== null) {
            ($this->handleEventsCallback)();
        }
    }

    public function negotiatedAlpn(): ?string
    {
        $alpn = ($this->negotiatedAlpnCallback)();
        if ($alpn !== null && !is_string($alpn)) {
            throw new UnexpectedValueException('php-quic returned an invalid negotiated ALPN value.');
        }

        return $alpn;
    }

    public function object(): object
    {
        return $this->connection;
    }

    public function openStream(bool $bidirectional): PhpQuicStream
    {
        $stream = ($this->openStreamCallback)($bidirectional);
        if (!is_object($stream)) {
            throw new UnexpectedValueException('php-quic openStream() returned an invalid stream.');
        }

        return new PhpQuicStream($stream);
    }

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
