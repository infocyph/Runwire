<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Network;

use Closure;
use Infocyph\Runwire\Loop\LoopInterface;
use Infocyph\Runwire\Network\Internal\HappyEyeballsAttempt;
use InvalidArgumentException;
use LogicException;
use OverflowException;
use Throwable;

/**
 * Staggers verified IPv6/IPv4 candidates through the same underlying Dialer.
 *
 * DNS and socket construction remain owned by AsyncDnsResolver and Dialer;
 * this class only owns the per-host address race and its cleanup.
 */
final class HappyEyeballsDialer
{
    private bool $closed = false;

    private int $nextId = 1;

    /** @var array<int, HappyEyeballsAttempt> */
    private array $pending = [];

    /** Create a bounded race coordinator over the shared outgoing connector. */
    public function __construct(
        private readonly LoopInterface $loop,
        private readonly Dialer $dialer,
        private readonly AsyncDnsResolver $resolver,
        private readonly int $maxPending = 32,
        private readonly float $staggerSeconds = 0.25,
    ) {
        if ($maxPending < 1 || $maxPending > 4096
            || !is_finite($staggerSeconds) || $staggerSeconds <= 0 || $staggerSeconds > 2) {
            throw new InvalidArgumentException('Invalid Happy Eyeballs concurrency or stagger policy.');
        }
    }

    /** Cancel a pending address race and close all unhanded-off candidates. */
    public function cancel(int $id): bool
    {
        $attempt = $this->pending[$id] ?? null;
        if ($attempt === null) {
            return false;
        }
        $attempt->cancel();

        return true;
    }

    /** Abort pending races without closing the shared socket connector or resolver. */
    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        foreach (array_keys($this->pending) as $id) {
            $this->cancel($id);
        }
    }

    /**
     * Resolve a hostname asynchronously and race its A/AAAA addresses.
     *
     * @param callable(?Connection, ?Throwable):void $completion
     */
    public function connect(
        DialEndpoint|string $endpoint,
        callable $completion,
        ?DialOptions $options = null,
    ): int {
        $endpoint = is_string($endpoint) ? DialEndpoint::parse($endpoint) : $endpoint;
        if ($endpoint->scheme !== 'tcp' || $endpoint->host === null || $endpoint->port === null) {
            throw new InvalidArgumentException('Happy Eyeballs requires a TCP hostname or address.');
        }
        $id = $this->register($completion, $options ?? new DialOptions());
        if ($endpoint->isIpLiteral) {
            $this->pending[$id]->runResolved([$endpoint->host], $endpoint->port);
        } else {
            $this->pending[$id]->runHostname($endpoint->host, $endpoint->port);
        }

        return $id;
    }

    /**
     * Race pre-resolved numeric addresses without performing DNS.
     *
     * @param list<string> $addresses
     * @param callable(?Connection, ?Throwable):void $completion
     */
    public function connectResolved(
        array $addresses,
        int $port,
        callable $completion,
        ?DialOptions $options = null,
    ): int {
        if ($port < 1 || $port > 65_535 || $addresses === [] || count($addresses) > 16) {
            throw new InvalidArgumentException('An address list of 1–16 entries and a valid TCP port are required.');
        }
        foreach ($addresses as $address) {
            if (filter_var($address, FILTER_VALIDATE_IP) === false) {
                throw new InvalidArgumentException('Only validated numeric IP candidates may be raced.');
            }
        }

        $id = $this->register($completion, $options ?? new DialOptions());
        $this->pending[$id]->runResolved($addresses, $port);

        return $id;
    }

    /** Return pending host races, excluding underlying candidate socket counts. */
    public function pendingCount(): int
    {
        return count($this->pending);
    }

    /**
     * @param callable(?Connection, ?Throwable):void $completion
     */
    private function register(callable $completion, DialOptions $options): int
    {
        if ($this->closed) {
            throw new LogicException('Happy Eyeballs dialer is closed.');
        }
        if (count($this->pending) >= $this->maxPending || $this->nextId === PHP_INT_MAX) {
            throw new OverflowException('Happy Eyeballs pending capacity exhausted.');
        }

        $id = $this->nextId++;
        $this->pending[$id] = new HappyEyeballsAttempt(
            $this->loop,
            $this->dialer,
            $this->resolver,
            $options,
            $this->staggerSeconds,
            Closure::fromCallable($completion),
            function () use ($id): void {
                unset($this->pending[$id]);
            },
        );

        return $id;
    }
}
