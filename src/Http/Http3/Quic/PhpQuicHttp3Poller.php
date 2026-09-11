<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Http3\Quic;

use Closure;
use InvalidArgumentException;
use LogicException;
use UnexpectedValueException;

final readonly class PhpQuicHttp3Poller
{
    public PhpQuicEventMasks $events;

    /** @var Closure(array<int, array{0: object, 1: int}>, ?float): array<int, int> */
    private Closure $pollCallback;

    /** @param callable(array<int, array{0: object, 1: int}>, ?float): array<int, int>|null $poll */
    public function __construct(PhpQuicEventMasks $events, ?callable $poll = null)
    {
        $this->events = $events;
        $callback = $poll ?? static fn(array $items, ?float $timeout): array => PhpQuicApi::poll($items, $timeout);
        /** @var Closure(array<int, array{0: object, 1: int}>, ?float): array<int, int> $closure */
        $closure = Closure::fromCallable($callback);
        $this->pollCallback = $closure;
    }

    public function listenerAcceptReady(PhpQuicListener $listener, array $ready): bool
    {
        return $this->objectReady($listener->object(), $ready, $this->events->acceptConnection);
    }

    public function listenerErrorReady(PhpQuicListener $listener, array $ready): bool
    {
        return $this->objectReady($listener->object(), $ready, $this->events->error);
    }

    /**
     * @param list<PhpQuicHttp3Connection> $connections
     * @return array<int, int>
     */
    public function poll(
        ?PhpQuicListener $listener,
        array $connections,
        bool $acceptConnections,
        ?float $timeoutSeconds,
    ): array {
        if ($timeoutSeconds !== null && (!is_finite($timeoutSeconds) || $timeoutSeconds < 0)) {
            throw new InvalidArgumentException('HTTP/3 QUIC poll timeout must be finite and non-negative.');
        }

        $items = [];
        if ($listener !== null) {
            $mask = $this->events->error | ($acceptConnections ? $this->events->acceptConnection : 0);
            $this->putPollItem($items, $listener->object(), $mask);
        }
        foreach ($connections as $connection) {
            foreach ($connection->pollItems($this->events) as $key => $item) {
                if (isset($items[$key]) && $items[$key][0] !== $item[0]) {
                    throw new LogicException('QUIC poll object id collision detected.');
                }
                $items[$key] = $item;
            }
        }
        if ($items === []) {
            return [];
        }

        $ready = ($this->pollCallback)($items, $timeoutSeconds);
        foreach ($ready as $key => $mask) {
            if (!is_int($key) || !is_int($mask) || !isset($items[$key])) {
                throw new UnexpectedValueException('HTTP/3 QUIC poll callback returned an invalid readiness map.');
            }
        }

        return $ready;
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
