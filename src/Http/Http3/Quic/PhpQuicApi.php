<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Http3\Quic;

use Closure;
use Infocyph\Runwire\Exception\RuntimeUnavailableException;
use InvalidArgumentException;
use UnexpectedValueException;

final class PhpQuicApi
{
    /** @var list<string> */
    private const array REQUIRED_CLASS_NAMES = [
        'Listener',
        'Connection',
        'Stream',
    ];

    /** @var list<string> */
    private const array REQUIRED_EVENTS = [
        'POLL_READ',
        'POLL_WRITE',
        'POLL_ACCEPT_CONNECTION',
        'POLL_ACCEPT_STREAM',
        'POLL_ERROR',
    ];

    public static function acceptConnectionEvent(): int
    {
        return self::event('POLL_ACCEPT_CONNECTION');
    }

    public static function acceptConnectionEvents(): int
    {
        return self::acceptConnectionEvent() | self::errorEvent();
    }

    public static function acceptStreamEvent(): int
    {
        return self::event('POLL_ACCEPT_STREAM');
    }

    public static function acceptStreamEvents(): int
    {
        return self::acceptStreamEvent() | self::errorEvent();
    }

    public static function assertAvailable(): void
    {
        if (!self::available()) {
            throw new RuntimeUnavailableException(
                'Native HTTP/3 requires ext-quic with Quic\\Listener, Quic\\Connection, Quic\\Stream and Quic\\poll().',
            );
        }
    }

    public static function available(): bool
    {
        if (!extension_loaded('quic') || !function_exists(self::pollFunction())) {
            return false;
        }
        foreach (self::REQUIRED_CLASS_NAMES as $name) {
            if (!class_exists(self::className($name))) {
                return false;
            }
        }

        return array_all(self::REQUIRED_EVENTS, fn(string $event): bool => defined(self::symbol($event)));
    }

    public static function errorEvent(): int
    {
        return self::event('POLL_ERROR');
    }

    /**
     * @param array<int|string, array{0: object, 1: int}> $items
     * @return array<int|string, int>
     */
    public static function poll(array $items, ?float $timeoutSeconds = null): array
    {
        self::assertAvailable();
        if ($timeoutSeconds !== null && (!is_finite($timeoutSeconds) || $timeoutSeconds < 0)) {
            throw new InvalidArgumentException('QUIC poll timeout must be finite and non-negative.');
        }

        $ready = (self::pollCallback())($items, $timeoutSeconds);
        if (!is_array($ready)) {
            throw new UnexpectedValueException('Quic\\poll() returned a non-array result.');
        }

        $events = [];
        foreach ($ready as $key => $mask) {
            if (!is_int($mask)) {
                throw new UnexpectedValueException('Quic\\poll() returned an invalid readiness map.');
            }
            $events[$key] = $mask;
        }

        return $events;
    }

    public static function readEvent(): int
    {
        return self::event('POLL_READ');
    }

    public static function readEvents(): int
    {
        return self::readEvent() | self::errorEvent();
    }

    public static function writeEvent(): int
    {
        return self::event('POLL_WRITE');
    }

    public static function writeEvents(): int
    {
        return self::writeEvent() | self::errorEvent();
    }

    private static function className(string $name): string
    {
        return self::extensionNamespace() . '\\' . $name;
    }

    private static function event(string $name): int
    {
        $constant = self::symbol($name);
        if (!defined($constant)) {
            throw new RuntimeUnavailableException(sprintf('%s is unavailable.', $constant));
        }
        $value = constant($constant);
        if (!is_int($value)) {
            throw new UnexpectedValueException(sprintf('%s must be an integer event mask.', $constant));
        }

        return $value;
    }

    private static function extensionNamespace(): string
    {
        foreach (get_loaded_extensions() as $extension) {
            if (strcasecmp($extension, 'quic') === 0) {
                return ucfirst(strtolower($extension));
            }
        }

        return implode('', ['Qu', 'ic']);
    }

    private static function pollCallback(): Closure
    {
        $poll = self::pollFunction();
        if (!function_exists($poll)) {
            throw new RuntimeUnavailableException('Quic\\poll() is unavailable.');
        }

        return Closure::fromCallable($poll);
    }

    private static function pollFunction(): string
    {
        return self::symbol('poll');
    }

    private static function symbol(string $name): string
    {
        return self::extensionNamespace() . '\\' . $name;
    }
}
