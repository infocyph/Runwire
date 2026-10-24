<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Http3\Quic;

use Closure;
use InvalidArgumentException;
use UnexpectedValueException;

/**
 * Wraps a native ext-quic stream with validated read, write, and lifecycle operations.
 */
final readonly class PhpQuicStream
{
    /** @var Closure(): void */
    private Closure $endCallback;

    /** @var Closure(): int */
    private Closure $idCallback;

    /** @var Closure(): bool */
    private Closure $isBidirectionalCallback;

    /** @var Closure(int): ?string */
    private Closure $readCallback;

    /** @var Closure(int): void */
    private Closure $resetCallback;

    /** @var Closure(): ?int */
    private Closure $resetCodeCallback;

    /** @var Closure(string, bool): int */
    private Closure $writeCallback;

    /**
     * Wrap and validate a native QUIC stream object.
     */
    public function __construct(private object $stream)
    {
        /** @var Closure(): void $end */
        $end = self::callback($this->stream, 'end');
        $this->endCallback = $end;
        /** @var Closure(): int $id */
        $id = self::callback($this->stream, 'getId');
        $this->idCallback = $id;
        /** @var Closure(): bool $bidirectional */
        $bidirectional = self::callback($this->stream, 'isBidirectional');
        $this->isBidirectionalCallback = $bidirectional;
        /** @var Closure(int): ?string $read */
        $read = self::callback($this->stream, 'read');
        $this->readCallback = $read;
        /** @var Closure(int): void $reset */
        $reset = self::callback($this->stream, 'reset');
        $this->resetCallback = $reset;
        /** @var Closure(): ?int $resetCode */
        $resetCode = self::callback($this->stream, 'getResetCode');
        $this->resetCodeCallback = $resetCode;
        /** @var Closure(string, bool): int $write */
        $write = self::callback($this->stream, 'write');
        $this->writeCallback = $write;
    }

    /**
     * Determine whether the stream is bidirectional.
     */
    public function bidirectional(): bool
    {
        return ($this->isBidirectionalCallback)();
    }

    /**
     * Finish the local sending side of the stream.
     */
    public function end(): void
    {
        ($this->endCallback)();
    }

    /**
     * Return the non-negative QUIC stream identifier.
     */
    public function id(): int
    {
        $id = ($this->idCallback)();
        if ($id < 0) {
            throw new UnexpectedValueException('php-quic returned a negative stream id.');
        }

        return $id;
    }

    /**
     * Return the wrapped native stream object.
     */
    public function object(): object
    {
        return $this->stream;
    }

    /**
     * Read up to the requested number of bytes from the stream.
     */
    public function read(int $length): ?string
    {
        if ($length < 1) {
            throw new InvalidArgumentException('QUIC stream read length must be positive.');
        }

        return ($this->readCallback)($length);
    }

    /**
     * Reset the stream with an application error code.
     */
    public function reset(int $errorCode): void
    {
        if ($errorCode < 0) {
            throw new InvalidArgumentException('QUIC stream reset error code cannot be negative.');
        }

        ($this->resetCallback)($errorCode);
    }

    /**
     * Return the peer reset code when the stream was reset.
     */
    public function resetCode(): ?int
    {
        $code = ($this->resetCodeCallback)();
        if ($code !== null && $code < 0) {
            throw new UnexpectedValueException('php-quic returned a negative stream reset code.');
        }

        return $code;
    }

    /**
     * Write bytes to the stream and return the accepted byte count.
     */
    public function write(string $bytes, bool $fin = false): int
    {
        $written = ($this->writeCallback)($bytes, $fin);
        if ($written < 0 || $written > strlen($bytes)) {
            throw new UnexpectedValueException('php-quic returned an invalid stream write byte count.');
        }

        return $written;
    }

    private static function callback(object $object, string $method): Closure
    {
        $callable = [$object, $method];
        if (!is_callable($callable)) {
            throw new InvalidArgumentException(sprintf('php-quic stream object must provide %s().', $method));
        }

        return $object->{$method}(...);
    }
}
