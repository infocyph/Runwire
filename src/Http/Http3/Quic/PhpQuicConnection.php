<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Http3\Quic;

use Closure;
use InvalidArgumentException;
use UnexpectedValueException;

final class PhpQuicConnection
{
    private readonly Closure $acceptStreamCallback;

    private readonly Closure $closeCallback;

    private readonly object $connection;

    private readonly Closure $negotiatedAlpnCallback;

    private readonly Closure $openStreamCallback;

    private readonly Closure $setBlockingCallback;

    public function __construct(object $connection)
    {
        $this->connection = $connection;
        $this->acceptStreamCallback = self::callback($connection, 'acceptStream');
        $this->closeCallback = self::callback($connection, 'close');
        $this->negotiatedAlpnCallback = self::callback($connection, 'getNegotiatedAlpn');
        $this->openStreamCallback = self::callback($connection, 'openStream');
        $this->setBlockingCallback = self::callback($connection, 'setBlocking');
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

        return Closure::fromCallable($callable);
    }
}
