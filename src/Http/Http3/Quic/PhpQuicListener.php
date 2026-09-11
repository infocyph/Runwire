<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Http3\Quic;

use Closure;
use Infocyph\Runwire\Exception\ListenerException;
use InvalidArgumentException;
use Throwable;
use UnexpectedValueException;

final readonly class PhpQuicListener
{
    private Closure $acceptCallback;

    private Closure $closeCallback;

    private Closure $setBlockingCallback;

    public function __construct(private object $listener)
    {
        $this->acceptCallback = self::callback($this->listener, 'accept');
        $this->closeCallback = self::callback($this->listener, 'close');
        $this->setBlockingCallback = self::callback($this->listener, 'setBlocking');
    }

    /** @param array<string, mixed> $options */
    public static function bind(string $host, int $port, array $options): self
    {
        self::validateBindOptions($host, $port, $options);
        PhpQuicApi::assertAvailable();

        $class = 'Quic\\Listener';
        if (!class_exists($class)) {
            throw new ListenerException('Quic\\Listener is unavailable.');
        }

        try {
            $listener = new $class($host, $port, $options);
        } catch (Throwable $exception) {
            throw new ListenerException(
                sprintf('Unable to bind HTTP/3 QUIC listener on %s:%d: %s', $host, $port, $exception->getMessage()),
                0,
                $exception,
            );
        }
        if (!is_object($listener)) {
            throw new ListenerException('Quic\\Listener construction returned an invalid listener.');
        }

        $wrapped = new self($listener);
        $wrapped->setNonBlocking();

        return $wrapped;
    }

    public function accept(): ?PhpQuicConnection
    {
        $connection = ($this->acceptCallback)();
        if ($connection === null) {
            return null;
        }
        if (!is_object($connection)) {
            throw new UnexpectedValueException('php-quic listener accept() returned an invalid connection.');
        }

        $wrapped = new PhpQuicConnection($connection);
        $wrapped->setNonBlocking();

        return $wrapped;
    }

    public function close(): void
    {
        ($this->closeCallback)();
    }

    public function object(): object
    {
        return $this->listener;
    }

    public function setNonBlocking(): void
    {
        ($this->setBlockingCallback)(false);
    }

    private static function callback(object $object, string $method): Closure
    {
        $callable = [$object, $method];
        if (!is_callable($callable)) {
            throw new InvalidArgumentException(sprintf('php-quic listener object must provide %s().', $method));
        }

        return $object->{$method}(...);
    }

    private static function supportsH3(mixed $alpn): bool
    {
        if (is_string($alpn)) {
            return in_array('h3', array_map(trim(...), explode(',', $alpn)), true);
        }
        if (!is_array($alpn)) {
            return false;
        }

        return array_any($alpn, fn(mixed $protocol): bool => $protocol === 'h3');
    }

    /** @param array<string, mixed> $options */
    private static function validateBindOptions(string $host, int $port, array $options): void
    {
        if ($host === '') {
            throw new InvalidArgumentException('HTTP/3 QUIC listener host cannot be empty.');
        }
        if ($port < 0 || $port > 65_535) {
            throw new InvalidArgumentException('HTTP/3 QUIC listener port must be between 0 and 65535.');
        }

        $certificate = $options['local_cert'] ?? null;
        if (!is_string($certificate) || $certificate === '' || !is_file($certificate)) {
            throw new InvalidArgumentException('HTTP/3 QUIC listener requires an existing local_cert file.');
        }
        $privateKey = $options['local_pk'] ?? null;
        if ($privateKey !== null && (!is_string($privateKey) || $privateKey === '' || !is_file($privateKey))) {
            throw new InvalidArgumentException('HTTP/3 QUIC local_pk must reference an existing file when supplied.');
        }
        if (!self::supportsH3($options['alpn'] ?? $options['alpn_protocols'] ?? null)) {
            throw new InvalidArgumentException('HTTP/3 QUIC listener must advertise the h3 ALPN protocol.');
        }
        if (isset($options['reuse_port']) && !is_bool($options['reuse_port'])) {
            throw new InvalidArgumentException('HTTP/3 QUIC reuse_port option must be boolean.');
        }
    }
}
