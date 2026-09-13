<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Runtime\Internal;

use Infocyph\Runwire\Supervisor\Enum\ShutdownReason;
use Infocyph\Runwire\Supervisor\WorkerRecyclePolicy;

final class WorkerRecycleState
{
    private const int NANOS_PER_SECOND = 1_000_000_000;

    private readonly int $effectiveMaxLifetimeSeconds;

    private readonly int $effectiveMaxRequests;

    private readonly int $startedAtNs;

    private int $currentMemoryBytes = 0;

    private int $peakMemoryBytes = 0;

    private int $requestsTotal = 0;

    public function __construct(
        private readonly WorkerRecyclePolicy $policy,
        ?int $seed = null,
        ?int $startedAtNs = null,
    ) {
        $seed ??= ((int) hrtime(true)) ^ (getmypid() ?: 0);
        $this->startedAtNs = $startedAtNs ?? (int) hrtime(true);
        $this->effectiveMaxRequests = $policy->maxRequests === 0
            ? 0
            : $policy->maxRequests + self::jitter($seed, 0x13579BDF, $policy->jitterRequests);
        $this->effectiveMaxLifetimeSeconds = $policy->maxLifetimeSeconds === 0
            ? 0
            : $policy->maxLifetimeSeconds + self::jitter($seed, 0x2468ACE, $policy->jitterSeconds);
    }

    public function currentMemoryBytes(): int
    {
        return $this->currentMemoryBytes;
    }

    public function effectiveMaxLifetimeSeconds(): int
    {
        return $this->effectiveMaxLifetimeSeconds;
    }

    public function effectiveMaxRequests(): int
    {
        return $this->effectiveMaxRequests;
    }

    public function peakMemoryBytes(): int
    {
        return $this->peakMemoryBytes;
    }

    public function recordRequestCompleted(
        bool $enforceRequestLimit = true,
        ?int $nowNs = null,
        ?int $currentMemoryBytes = null,
        ?int $peakMemoryBytes = null,
    ): bool {
        ++$this->requestsTotal;
        $this->currentMemoryBytes = $currentMemoryBytes ?? memory_get_usage(true);
        $observedPeak = $peakMemoryBytes ?? memory_get_peak_usage(true);
        $this->peakMemoryBytes = max($this->peakMemoryBytes, $observedPeak);

        return $this->recycleReason($enforceRequestLimit, $nowNs) !== null;
    }

    public function recycleReason(
        bool $enforceRequestLimit = true,
        ?int $nowNs = null,
    ): ?ShutdownReason {
        if (
            $enforceRequestLimit
            && $this->effectiveMaxRequests > 0
            && $this->requestsTotal >= $this->effectiveMaxRequests
        ) {
            return ShutdownReason::RECYCLE_REQUEST_LIMIT;
        }

        if ($this->policy->maxMemoryBytes > 0 && $this->currentMemoryBytes >= $this->policy->maxMemoryBytes) {
            return ShutdownReason::RECYCLE_MEMORY_LIMIT;
        }

        if ($this->effectiveMaxLifetimeSeconds === 0) {
            return null;
        }

        $elapsed = ($nowNs ?? (int) hrtime(true)) - $this->startedAtNs;

        return $elapsed >= $this->effectiveMaxLifetimeSeconds * self::NANOS_PER_SECOND
            ? ShutdownReason::RECYCLE_LIFETIME
            : null;
    }

    public function requestsTotal(): int
    {
        return $this->requestsTotal;
    }

    public function shouldRecycle(bool $enforceRequestLimit = true, ?int $nowNs = null): bool
    {
        return $this->recycleReason($enforceRequestLimit, $nowNs) !== null;
    }

    private static function jitter(int $seed, int $salt, int $maximum): int
    {
        if ($maximum === 0) {
            return 0;
        }

        $value = ($seed ^ $salt) & 0x7FFFFFFF;
        $value = ($value * 1_103_515_245 + 12_345) & 0x7FFFFFFF;

        return $value % ($maximum + 1);
    }
}
