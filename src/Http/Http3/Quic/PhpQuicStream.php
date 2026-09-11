<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Http3\Quic;

use Closure;
use InvalidArgumentException;
use UnexpectedValueException;

final class PhpQuicStream
{
    /** @var Closure(): void */
    private readonly Closure $endCallback;

    /** @var Closure(): int */
    private readonly Closure $idCallback;

    /** @var Closure(): bool */
    private readonly Closure $isBidirectionalCallback;

    /** @var Closure(int): ?string */
    private readonly Closure $readCallback;

    /** @var Closure(int): void */
    private readonly Closure $resetCallback;

    private readonly object $stream;

    /** @var Closure(string, bool): int */
    private readonly Closure $writeCallback;

    public function __construct(object $stream)
    {
        $this->stream = $stream;
        /** @var Closure(): void $end */
        $end = self::callback($stream, 'end');
        $this->endCallback = $end;
        /** @var Closure(): int $id */
        $id = self::callback($stream, 'getId');
        $this->idCallback = $id;
        /** @var Closure(): bool $bidirectional */
        $bidirectional = self::callback($stream, 'isBidirectional');
        $this->isBidirectionalCallback = $bidirectional;
        /** @var Closure(int): ?string $read */
        $read = self::callback($stream, 'read');
        $this->readCallback = $read;
        /** @var Closure(int): void $reset */
        $reset = self::callback($stream, 'reset');
        $this->resetCallback = $reset;
        /** @var Closure(string, bool): int $write */
        $write = self::callback($stream, 'write');
        $this->writeCallback = $write;
    }

    public function bidirectional(): bool
    {
        return ($this->isBidirectionalCallback)();
    }

    public function end(): void
    {
        ($this->endCallback)();
    }

    public function id(): int
    {
        $id = ($this->idCallback)();
        if ($id < 0) {
            throw new UnexpectedValueException('php-quic returned a negative stream id.');
        }

        return $id;
    }

    public function object(): object
    {
        return $this->stream;
    }

    public function read(int $length): ?string
    {
        if ($length < 1) {
            throw new InvalidArgumentException('QUIC stream read length must be positive.');
        }

        return ($this->readCallback)($length);
    }

    public function reset(int $errorCode): void
    {
        if ($errorCode < 0) {
            throw new InvalidArgumentException('QUIC stream reset error code cannot be negative.');
        }

        ($this->resetCallback)($errorCode);
    }

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

        return Closure::fromCallable($callable);
    }
}
