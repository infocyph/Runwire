<?php

declare(strict_types=1);

namespace Infocyph\Runwire;

use Infocyph\Runwire\Metrics\MetricsProviderInterface;
use Infocyph\Runwire\Metrics\RuntimeMetrics;
use Infocyph\Runwire\Metrics\RuntimeMetricsSnapshot;
use Infocyph\Runwire\Runtime\Enum\RuntimeDriver;
use InvalidArgumentException;

final readonly class RuntimeContext implements MetricsProviderInterface
{
    public function __construct(
        public RuntimeDriver $driver,
        public string $mode,
        public ?int $workerSlot,
        public ?int $generation,
        public int $pid,
        public bool $persistent,
        public bool $concurrent,
        public bool $ownsListener,
        public bool $ownsEventLoop,
        public bool $ownsWorkerPool,
        public RuntimeCapabilities $capabilities,
        public RuntimeMetrics $metrics = new RuntimeMetrics(),
    ) {
        if ($mode === '' || strlen($mode) > 32 || preg_match('/^[a-z0-9._-]+$/D', $mode) !== 1) {
            throw new InvalidArgumentException('Runtime mode must be a 1-32 character lowercase identifier.');
        }
        if ($workerSlot !== null && $workerSlot < 0) {
            throw new InvalidArgumentException('Runtime worker slot must be null or non-negative.');
        }
        if ($generation !== null && $generation < 0) {
            throw new InvalidArgumentException('Runtime generation must be null or non-negative.');
        }
        if ($pid < 0) {
            throw new InvalidArgumentException('Runtime PID must be non-negative.');
        }
        if ($driver !== $capabilities->driver) {
            throw new InvalidArgumentException('Runtime context driver must match its capabilities.');
        }
    }

    public static function fromCapabilities(
        RuntimeCapabilities $capabilities,
        string $mode,
        ?int $workerSlot = null,
        ?int $generation = null,
        ?int $pid = null,
        ?bool $concurrent = null,
        ?RuntimeMetrics $metrics = null,
    ): self {
        return new self(
            driver: $capabilities->driver,
            mode: $mode,
            workerSlot: $workerSlot,
            generation: $generation,
            pid: $pid ?? self::currentPid(),
            persistent: $capabilities->persistentApplication,
            concurrent: $concurrent ?? $capabilities->supportsCoroutines,
            ownsListener: $capabilities->ownsListener,
            ownsEventLoop: $capabilities->ownsEventLoop,
            ownsWorkerPool: $capabilities->ownsWorkerPool,
            capabilities: $capabilities,
            metrics: $metrics ?? new RuntimeMetrics(),
        );
    }

    public static function standalone(): self
    {
        $capabilities = new RuntimeCapabilities(RuntimeDriver::NATIVE);

        return self::fromCapabilities($capabilities, 'standalone', concurrent: false);
    }

    public function snapshot(): RuntimeMetricsSnapshot
    {
        return $this->metrics->snapshot();
    }

    private static function currentPid(): int
    {
        $pid = getmypid();

        return is_int($pid) ? $pid : 0;
    }
}
