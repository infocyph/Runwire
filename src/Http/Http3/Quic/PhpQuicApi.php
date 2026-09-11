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
    private const array REQUIRED_CLASSES = [
        'Quic\\Listener',
        'Quic\\Connection',
        'Quic\\Stream',
    ];

    /** @var list<string> */
    private const array REQUIRED_EVENTS = [
        'POLL_READ',
        'POLL_WRITE',
        'POLL_ACCEPT_CONNECTION',
        'POLL_ACCEPT_STREAM',
        'POLL_ERROR',
    ];

    public static function acceptConnectionEvents(): int
    {
        return self::event('POLL_ACCEPT_CONNECTION') | self::event('POLL_ERROR');
    }

    public static function acceptStreamEvents(): int
    {
        return self::event('POLL_ACCEPT_STREAM') | self::event('POLL_ERROR');
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
        foreach (self::REQUIRED_CLASSES as $class) {
            if (!class_exists($class)) {
                return false;
            }
        }

        return array_all(self::REQUIRED_EVENTS, fn(string $event): bool => defined('Quic\\' . $event));
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

    public static function readEvents(): int
    {
        return self::event('POLL_READ') | self::event('POLL_ERROR');
    }

    public static function writeEvents(): int
    {
        return self::event('POLL_WRITE') | self::event('POLL_ERROR');
    }

    private static function event(string $name): int
    {
        $constant = 'Quic\\' . $name;
        if (!defined($constant)) {
            throw new RuntimeUnavailableException(sprintf('%s is unavailable.', $constant));
        }
        $value = constant($constant);
        if (!is_int($value)) {
            throw new UnexpectedValueException(sprintf('%s must be an integer event mask.', $constant));
        }

        return $value;
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
        return implode('\\', ['Quic', 'poll']);
    }
}
