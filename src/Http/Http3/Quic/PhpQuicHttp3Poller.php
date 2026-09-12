<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Http3\Quic;

use Closure;
use InvalidArgumentException;
use LogicException;
use UnexpectedValueException;

final readonly class PhpQuicHttp3Poller
{
    /** @var Closure(array<int, array{0: object, 1: int}>, ?float): mixed */
    private Closure $pollCallback;

    /** @param callable(array<int, array{0: object, 1: int}>, ?float): array<int, int>|null $poll */
    public function __construct(public PhpQuicEventMasks $events, ?callable $poll = null)
    {
        $callback = $poll ?? PhpQuicApi::poll(...);
        /** @var Closure(array<int, array{0: object, 1: int}>, ?float): mixed $closure */
        $closure = Closure::fromCallable($callback);
        $this->pollCallback = $closure;
    }

    /** @param array<int, int> $ready */
    public function listenerAcceptReady(PhpQuicListener $listener, array $ready): bool
    {
        return $this->objectReady($listener->object(), $ready, $this->events->acceptConnection);
    }

    /** @param array<int, int> $ready */
    public function listenerErrorReady(PhpQuicListener $listener, array $ready): bool
    {
        return $this->objectReady($listener->object(), $ready, $this->events->error);
    }

    /**
     * @param list<PhpQuicHttp3Connection> $connections
     * @param list<PhpQuicConnection> $pendingConnections
     * @return array<int, int>
     */
    public function poll(
        ?PhpQuicListener $listener,
        array $connections,
        bool $acceptConnections,
        ?float $timeoutSeconds,
        array $pendingConnections = [],
    ): array {
        self::validateTimeout($timeoutSeconds);
        $items = $this->buildPollItems($listener, $connections, $pendingConnections, $acceptConnections);
        if ($items === []) {
            return [];
        }

        return self::normalizeReady(($this->pollCallback)($items, $timeoutSeconds), $items);
    }

    /**
     * @param array<int, array{0: object, 1: int}> $items
     * @return array<int, int>
     */
    private static function normalizeReady(mixed $ready, array $items): array
    {
        if (!is_array($ready)) {
            throw new UnexpectedValueException('HTTP/3 QUIC poll callback returned an invalid readiness map.');
        }

        $normalized = [];
        foreach ($ready as $key => $mask) {
            if (!is_int($key) || !is_int($mask) || !isset($items[$key])) {
                throw new UnexpectedValueException('HTTP/3 QUIC poll callback returned an invalid readiness map.');
            }
            $normalized[$key] = $mask;
        }

        return $normalized;
    }

    private static function validateTimeout(?float $timeoutSeconds): void
    {
        if ($timeoutSeconds !== null && (!is_finite($timeoutSeconds) || $timeoutSeconds < 0)) {
            throw new InvalidArgumentException('HTTP/3 QUIC poll timeout must be finite and non-negative.');
        }
    }

    /**
     * @param list<PhpQuicHttp3Connection> $connections
     * @param list<PhpQuicConnection> $pendingConnections
     * @return array<int, array{0: object, 1: int}>
     */
    private function buildPollItems(
        ?PhpQuicListener $listener,
        array $connections,
        array $pendingConnections,
        bool $acceptConnections,
    ): array {
        $items = [];
        if ($listener !== null && $acceptConnections) {
            $this->putPollItem($items, $listener->object(), $this->events->acceptConnection);
        }
        foreach ($pendingConnections as $connection) {
            $this->putPollItem(
                $items,
                $connection->object(),
                $this->events->acceptStream | $this->events->error,
            );
        }
        foreach ($connections as $connection) {
            $this->mergePollItems($items, $connection->pollItems($this->events));
        }

        return $items;
    }

    /**
     * @param array<int, array{0: object, 1: int}> $items
     * @param array<int, array{0: object, 1: int}> $incoming
     */
    private function mergePollItems(array &$items, array $incoming): void
    {
        foreach ($incoming as $key => $item) {
            if (isset($items[$key]) && $items[$key][0] !== $item[0]) {
                throw new LogicException('QUIC poll object id collision detected.');
            }
            $items[$key] = $item;
        }
    }

    /** @param array<int, int> $ready */
    private function objectReady(object $object, array $ready, int $event): bool
    {
        return (($ready[spl_object_id($object)] ?? 0) & $event) !== 0;
    }

    /** @param array<int, array{0: object, 1: int}> $items */
    private function putPollItem(array &$items, object $object, int $events): void
    {
        $items[spl_object_id($object)] = [$object, $events];
    }
}
