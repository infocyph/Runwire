<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Network\Internal;

use Closure;
use Infocyph\Runwire\CancellationSubscription;
use Infocyph\Runwire\Exception\CancelledException;
use Infocyph\Runwire\Loop\LoopInterface;
use Infocyph\Runwire\Network\DnsResolverInterface;
use Infocyph\Runwire\Network\Connection;
use Infocyph\Runwire\Network\Dialer;
use Infocyph\Runwire\Network\DialOptions;
use Infocyph\Runwire\Runtime\Enum\CancellationReason;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * @internal Single logical DNS + staggered-address dial, with exactly-once settlement.
 */
final class HappyEyeballsAttempt
{
    private const int MAX_CANDIDATES = 16;

    /** @var list<string> */
    private array $candidates = [];

    /** @var array<int, true> */
    private array $children = [];

    private bool $completed = false;

    private ?int $dnsJob = null;

    private ?Throwable $lastError = null;

    private ?int $launchTimer = null;

    private int $nextCandidate = 0;

    private ?int $overallDeadlineNs = null;

    private int $port = 0;

    private float $remaining = 0;

    private ?CancellationSubscription $subscription = null;

    private ?int $timeoutTimer = null;

    /**
     * @param Closure(?Connection, ?Throwable):void $completion
     * @param Closure():void $onSettled
     */
    public function __construct(
        private readonly LoopInterface $loop,
        private readonly Dialer $dialer,
        private readonly DnsResolverInterface $resolver,
        private readonly DialOptions $options,
        private readonly float $staggerSeconds,
        private readonly Closure $completion,
        private readonly Closure $onSettled,
    ) {
        if (!is_finite($staggerSeconds) || $staggerSeconds <= 0) {
            throw new InvalidArgumentException('A positive finite stagger interval is required.');
        }
    }

    /** Cancel this logical attempt and all candidates exactly once. */
    public function cancel(): void
    {
        $this->finish(null, new CancelledException(CancellationReason::HOST_CANCELLED));
    }

    /** Resolve A/AAAA using worker processes before starting candidate sockets. */
    public function runHostname(string $hostname, int $port): void
    {
        $this->port = $port;
        $this->begin();

        if ($this->completed) {
            return;
        }

        try {
            $this->dnsJob = $this->resolver->resolve(
                $hostname,
                function (array $addresses, ?Throwable $error): void {
                    $this->dnsJob = null;

                    if ($this->completed) {
                        return;
                    }

                    if ($error !== null) {
                        $this->finish(null, $error);

                        return;
                    }
                    $this->setCandidates($addresses);
                },
                $this->options->cancellation,
                $this->options->deadline,
            );
        } catch (Throwable $error) {
            $this->finish(null, $error);
        }
    }

    /**
     * Start an already resolved address race using the shared numeric connector.
     *
     * @param list<string> $addresses
     */
    public function runResolved(array $addresses, int $port): void
    {
        $this->port = $port;
        $this->begin();

        if (!$this->completed) {
            $this->setCandidates($addresses);
        }
    }

    private static function addressUri(string $address, int $port): string
    {
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            return sprintf('tcp://[%s]:%d', $address, $port);
        }

        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return sprintf('tcp://%s:%d', $address, $port);
        }

        throw new InvalidArgumentException('Resolved candidate must be a numeric IP address.');
    }

    /**
     * @param list<string> $addresses
     * @return list<string>
     */
    private static function orderAddresses(array $addresses, int $port): array
    {
        $families = [4 => [], 6 => []];
        $first = null;

        foreach (array_slice(array_unique($addresses), 0, self::MAX_CANDIDATES) as $address) {
            $family = filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? 6 : 4;
            $first ??= $family;
            $families[$family][] = self::addressUri($address, $port);
        }

        $ordered = [];
        $other = $first === 6 ? 4 : 6;

        while ($families[$first ?? 4] !== [] || $families[$other] !== []) {
            foreach ([$first ?? 4, $other] as $family) {
                if ($families[$family] !== []) {
                    $ordered[] = array_shift($families[$family]);
                }
            }
        }

        return $ordered;
    }

    private function begin(): void
    {
        $this->remaining = $this->options->connectTimeoutSeconds;

        foreach ([$this->options->deadline, $this->options->cancellation?->deadline()] as $deadline) {
            $limit = $deadline?->remainingSeconds();

            if ($limit !== null) {
                $this->remaining = min($this->remaining, $limit);
            }
        }

        $this->overallDeadlineNs = hrtime(true) + (int) ($this->remaining * 1_000_000_000);
        $this->timeoutTimer = $this->loop->delay(max(0, $this->remaining), function (): void {
            $this->finish(null, new RuntimeException('Overall hostname connection deadline exceeded.'));
        });
        $token = $this->options->cancellation;

        if ($token === null) {
            return;
        }
        $subscription = $token->onCancel(function (): void {
            $this->cancel();
        });

        if ($this->completed) {
            $subscription->unsubscribe();
        } else {
            $this->subscription = $subscription;
        }
    }

    private function finish(?Connection $winner, ?Throwable $error): void
    {
        if ($this->completed) {
            return;
        }
        $this->completed = true;
        ($this->onSettled)();

        foreach ([$this->launchTimer, $this->timeoutTimer] as $timer) {
            if ($timer !== null) {
                $this->loop->cancel($timer);
            }
        }
        $this->launchTimer = $this->timeoutTimer = null;
        $this->subscription?->unsubscribe();
        $this->subscription = null;

        if ($this->dnsJob !== null) {
            $this->resolver->cancel($this->dnsJob);
            $this->dnsJob = null;
        }

        foreach (array_keys($this->children) as $child) {
            $this->dialer->cancel($child);
        }
        $this->children = [];
        ($this->completion)($winner, $error);
    }

    private function launchCandidate(): void
    {
        if ($this->completed) {
            return;
        }

        while ($this->nextCandidate < count($this->candidates) && count($this->children) < 2) {
            $uri = $this->candidates[$this->nextCandidate++];

            try {
                $childId = 0;
                $childId = $this->dialer->connect(
                    $uri,
                    function (?Connection $connection, ?Throwable $error) use (&$childId): void {
                        $this->onCandidate($childId, $connection, $error);
                    },
                    new DialOptions(
                        connectTimeoutSeconds: max(
                            0.001,
                            ($this->overallDeadlineNs - hrtime(true)) / 1_000_000_000,
                        ),
                        connectionLimits: $this->options->connectionLimits,
                        tcpNoDelay: $this->options->tcpNoDelay,
                        tls: $this->options->tls,
                        addressPolicy: $this->options->addressPolicy,
                    ),
                );
                $this->children[$childId] = true;

                break;
            } catch (Throwable $error) {
                $this->lastError = $error;
            }
        }
        $this->scheduleNext();
    }

    private function onCandidate(int $childId, ?Connection $connection, ?Throwable $error): void
    {
        unset($this->children[$childId]);

        if ($this->completed) {
            $connection?->abort();

            return;
        }

        if ($connection !== null) {
            $this->finish($connection, null);

            return;
        }
        $this->lastError = $error;

        if ($this->launchTimer !== null) {
            $this->loop->cancel($this->launchTimer);
            $this->launchTimer = null;
        }
        $this->launchCandidate();
    }

    private function scheduleNext(): void
    {
        if ($this->completed) {
            return;
        }

        if ($this->nextCandidate >= count($this->candidates)) {
            if ($this->children === []) {
                $this->finish(null, $this->lastError ?? new RuntimeException('Every dial candidate failed.'));
            }

            return;
        }

        if ($this->launchTimer === null && count($this->children) < 2) {
            $this->launchTimer = $this->loop->delay($this->staggerSeconds, function (): void {
                $this->launchTimer = null;
                $this->launchCandidate();
            });
        }
    }

    /** @param list<string> $addresses */
    private function setCandidates(array $addresses): void
    {
        $this->candidates = self::orderAddresses($addresses, $this->port);

        if ($this->candidates === []) {
            $this->finish(null, new RuntimeException('No resolved addresses to dial.'));

            return;
        }

        $this->launchCandidate();
    }
}
