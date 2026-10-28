<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Network;

use Closure;
use Infocyph\Runwire\CancellationSubscription;
use Infocyph\Runwire\CancellationToken;
use Infocyph\Runwire\Exception\CancelledException;
use Infocyph\Runwire\Loop\LoopInterface;
use Infocyph\Runwire\RequestDeadline;
use Infocyph\Runwire\Runtime\Enum\CancellationReason;
use InvalidArgumentException;
use LogicException;
use OverflowException;
use RuntimeException;
use Throwable;

/** Opt-in bounded DNS TTL cache and in-flight coalescing owned by one worker and tenant. */
final class CachedDnsResolver implements DnsResolverInterface
{
    private readonly int $pid;

    private bool $closed = false;

    private int $nextId = 1;

    /** @var array<string,array{addresses:list<string>,expires:float,negative:bool}> */
    private array $cache = [];

    /** @var array<string,array{job:int,waiters:array<int,true>}> */
    private array $pending = [];

    /** @var array<int,array{host:string,callback:Closure,subscription:?CancellationSubscription,timer:?int,deferred:?int}> */
    private array $waiters = [];

    /** Configure a bounded owner-local TTL cache without changing shared resolver ownership. */
    public function __construct(
        private readonly LoopInterface $loop,
        private readonly DnsResolverInterface $upstream,
        private readonly float $ttl = 10.0,
        private readonly float $negativeTtl = 1.0,
        private readonly int $maxEntries = 128,
        private readonly int $maxPending = 64,
        private readonly int $maxWaiters = 256,
    ) {
        if (!is_finite($ttl) || $ttl < 0 || $ttl > 300
            || !is_finite($negativeTtl) || $negativeTtl < 0 || $negativeTtl > 30
            || $maxEntries < 1 || $maxEntries > 4096 || $maxPending < 1 || $maxPending > 1024
            || $maxWaiters < 1 || $maxWaiters > 4096) {
            throw new InvalidArgumentException('DNS caching TTL and admission limits must be bounded.');
        }
        $pid = getmypid();
        if (!is_int($pid)) {
            throw new RuntimeException('Missing DNS resolver process owner.');
        }
        $this->pid = $pid;
    }

    /** Cancel exactly one caller; preserve unrelated coalesced waiters. */
    public function cancel(int $id): bool
    {
        $this->assertOwner();
        if (!isset($this->waiters[$id])) {
            return false;
        }
        $this->settle($id, [], new CancelledException(CancellationReason::HOST_CANCELLED));

        return true;
    }

    /** Cancel pending callers and drop cache entries without closing the shared upstream. */
    public function close(): void
    {
        $this->assertOwner();
        $this->closed = true;
        foreach (array_keys($this->waiters) as $id) {
            $this->cancel($id);
        }
        $this->cache = [];
    }

    /** @return array{cached:int, pendingHosts:int, waiters:int} */
    public function diagnostics(): array
    {
        $this->assertOwner();

        return ['cached' => count($this->cache), 'pendingHosts' => count($this->pending), 'waiters' => count($this->waiters)];
    }

    /** @param callable(list<string>,?Throwable):void $completion */
    public function resolve(
        string $hostname,
        callable $completion,
        ?CancellationToken $token = null,
        ?RequestDeadline $deadline = null,
    ): int {
        $host = $this->assertLookup($hostname);
        $entry = $this->cachedEntry($host);
        if ($entry === null && !isset($this->pending[$host]) && count($this->pending) >= $this->maxPending) {
            throw new OverflowException('DNS cache hostname capacity exhausted.');
        }
        $id = $this->nextId++;
        $this->waiters[$id] = ['host' => $host, 'callback' => Closure::fromCallable($completion),
            'subscription' => null, 'timer' => null, 'deferred' => null];
        if ($this->rejectCancelledCaller($id, $token, $deadline)) {
            return $id;
        }
        $this->watchCaller($id, $token, $deadline);
        if (isset($this->waiters[$id])) {
            $this->dispatch($host, $id, $entry);
        }

        return $id;
    }

    private function assertLookup(string $hostname): string
    {
        $this->assertOwner();
        if ($this->closed) {
            throw new LogicException('DNS cache is closed.');
        }
        if (DialEndpoint::parse('tcp://' . $hostname . ':1')->isIpLiteral) {
            throw new InvalidArgumentException('DNS caching requires an actual hostname.');
        }
        if (count($this->waiters) >= $this->maxWaiters || $this->nextId === PHP_INT_MAX) {
            throw new OverflowException('DNS cache waiter capacity exhausted.');
        }

        return strtolower($hostname);
    }

    /** @return array{addresses:list<string>,expires:float,negative:bool}|null */
    private function cachedEntry(string $host): ?array
    {
        $entry = $this->cache[$host] ?? null;
        if ($entry !== null && $entry['expires'] <= $this->loop->now()) {
            unset($this->cache[$host]);

            return null;
        }

        return $entry;
    }

    private function rejectCancelledCaller(int $id, ?CancellationToken $token, ?RequestDeadline $deadline): bool
    {
        if (!$token?->isCancelled() && !$deadline?->expired()) {
            return false;
        }
        $reason = $deadline?->expired() ? CancellationReason::DEADLINE_EXCEEDED
            : ($token?->reason() ?? CancellationReason::HOST_CANCELLED);
        $this->settle($id, [], new CancelledException($reason));

        return true;
    }

    /** @param array{addresses:list<string>,expires:float,negative:bool}|null $entry */
    private function dispatch(string $host, int $id, ?array $entry): void
    {
        if ($entry !== null) {
            $this->waiters[$id]['deferred'] = $this->loop->defer(function () use ($id, $entry): void {
                if (isset($this->waiters[$id])) {
                    $this->completeCached($id, $entry);
                }
            });

            return;
        }
        if (isset($this->pending[$host])) {
            $this->pending[$host]['waiters'][$id] = true;

            return;
        }
        $this->pending[$host] = ['job' => 0, 'waiters' => [$id => true]];
        try {
            $job = $this->upstream->resolve($host, function (array $addresses, ?Throwable $error) use ($host): void {
                $this->completeHost($host, $addresses, $error);
            });
            if (isset($this->pending[$host])) {
                $this->pending[$host]['job'] = $job;
            }
        } catch (Throwable $error) {
            $this->completeHost($host, [], $error);
        }
    }

    private function watchCaller(int $id, ?CancellationToken $token, ?RequestDeadline $deadline): void
    {
        $remaining = $deadline?->remainingSeconds();
        $tokenRemaining = $token?->deadline()->remainingSeconds();
        if ($tokenRemaining !== null) {
            $remaining = $remaining === null ? $tokenRemaining : min($remaining, $tokenRemaining);
        }
        if ($remaining !== null) {
            $this->waiters[$id]['timer'] = $this->loop->delay(max(0, $remaining), function () use ($id): void {
                $this->settle($id, [], new CancelledException(CancellationReason::DEADLINE_EXCEEDED));
            });
        }
        if ($token !== null) {
            $subscription = $token->onCancel(function () use ($id): void {
                $this->cancel($id);
            });
            if (isset($this->waiters[$id])) {
                $this->waiters[$id]['subscription'] = $subscription;
            } else {
                $subscription->unsubscribe();
            }
        }
    }

    /** @param array{addresses:list<string>,expires:float,negative:bool} $entry */
    private function completeCached(int $id, array $entry): void
    {
        if ($entry['expires'] <= $this->loop->now()) {
            $this->settle($id, [], new RuntimeException('DNS cached result expired before delivery.'));

            return;
        }
        $this->deliver($id, $entry['addresses'], $entry['negative']);
    }

    /** @param list<string> $addresses */
    private function completeHost(string $host, array $addresses, ?Throwable $error): void
    {
        $group = $this->pending[$host] ?? null;
        if ($group === null) {
            return;
        }
        unset($this->pending[$host]);
        $this->rememberHost($host, $addresses, $error);
        foreach (array_keys($group['waiters']) as $id) {
            if ($error !== null) {
                $this->settle($id, [], $error);

                continue;
            }
            $this->deliver($id, $addresses, false);
        }
    }

    /** @param list<string> $addresses */
    private function rememberHost(string $host, array $addresses, ?Throwable $error): void
    {
        $negative = $error !== null && $error->getMessage() === 'DNS returned no A or AAAA addresses.';
        $ttl = $error === null ? $this->ttl : ($negative ? $this->negativeTtl : 0.0);
        if ($ttl <= 0 || $this->closed) {
            return;
        }
        if (count($this->cache) >= $this->maxEntries) {
            $first = array_key_first($this->cache);
            if (is_string($first)) {
                unset($this->cache[$first]);
            }
        }
        $this->cache[$host] = ['addresses' => $addresses, 'expires' => $this->loop->now() + $ttl,
            'negative' => $negative];
    }

    /** @param list<string> $addresses */
    private function deliver(int $id, array $addresses, bool $negative): void
    {
        $this->settle($id, $negative ? [] : $addresses,
            $negative ? new RuntimeException('DNS returned no A or AAAA addresses.') : null);
    }

    /** @param list<string> $addresses */
    private function settle(int $id, array $addresses, ?Throwable $error): void
    {
        $waiter = $this->waiters[$id] ?? null;
        if ($waiter === null) {
            return;
        }
        unset($this->waiters[$id]);
        $waiter['subscription']?->unsubscribe();
        foreach ([$waiter['timer'], $waiter['deferred']] as $handle) {
            if ($handle !== null) {
                $this->loop->cancel($handle);
            }
        }
        $host = $waiter['host'];
        if (isset($this->pending[$host]['waiters'][$id])) {
            unset($this->pending[$host]['waiters'][$id]);
            if ($this->pending[$host]['waiters'] === []) {
                $job = $this->pending[$host]['job'];
                unset($this->pending[$host]);
                if ($job !== 0) {
                    $this->upstream->cancel($job);
                }
            }
        }
        ($waiter['callback'])($addresses, $error);
    }

    private function assertOwner(): void
    {
        if (getmypid() !== $this->pid) {
            throw new LogicException('DNS cache may not cross process/fork ownership boundaries.');
        }
    }
}
