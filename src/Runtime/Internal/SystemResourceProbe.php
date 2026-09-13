<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Runtime\Internal;

use Closure;
use Infocyph\Runwire\Runtime\SystemResources;
use InvalidArgumentException;

final class SystemResourceProbe
{
    /** @var Closure(string): ?string */
    private readonly Closure $reader;

    /**
     * @param callable(string): ?string|null $reader
     */
    public function __construct(
        ?callable $reader = null,
        private readonly ?int $hostCpuCount = null,
        private readonly ?int $hostMemoryBytes = null,
    ) {
        if ($hostCpuCount !== null && $hostCpuCount < 1) {
            throw new InvalidArgumentException('Host CPU count override must be positive.');
        }
        if ($hostMemoryBytes !== null && $hostMemoryBytes < 1) {
            throw new InvalidArgumentException('Host memory override must be positive.');
        }

        $this->reader = $reader === null
            ? static function (string $path): ?string {
                if (!is_file($path) || !is_readable($path)) {
                    return null;
                }

                $value = file_get_contents($path);

                return is_string($value) ? $value : null;
            }
            : Closure::fromCallable($reader);
    }

    public function probe(): SystemResources
    {
        $hostCpu = $this->hostCpuCount ?? $this->detectHostCpuCount();
        $cpuCandidates = [$hostCpu];
        $quotaCpu = $this->quotaCpuCount();
        if ($quotaCpu !== null) {
            $cpuCandidates[] = $quotaCpu;
        }
        $cpusetCpu = $this->cpusetCpuCount();
        if ($cpusetCpu !== null) {
            $cpuCandidates[] = $cpusetCpu;
        }

        $hostMemory = $this->hostMemoryBytes ?? $this->detectHostMemoryBytes();
        $cgroupMemory = $this->cgroupMemoryBytes();
        $effectiveMemory = match (true) {
            $hostMemory !== null && $cgroupMemory !== null => min($hostMemory, $cgroupMemory),
            $hostMemory !== null => $hostMemory,
            default => $cgroupMemory,
        };

        return new SystemResources(
            effectiveCpuCount: max(1, min($cpuCandidates)),
            effectiveMemoryBytes: $effectiveMemory,
        );
    }

    private static function parseCpuSet(string $value): ?int
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $count = 0;
        foreach (explode(',', $value) as $segment) {
            $segment = trim($segment);
            if (preg_match('/^(\d+)$/D', $segment, $single) === 1) {
                ++$count;

                continue;
            }
            if (preg_match('/^(\d+)-(\d+)$/D', $segment, $range) !== 1) {
                return null;
            }

            $start = (int) $range[1];
            $end = (int) $range[2];
            if ($end < $start) {
                return null;
            }
            $count += $end - $start + 1;
        }

        return $count > 0 ? $count : null;
    }

    private static function quotaToCpuCount(int $quota, int $period): ?int
    {
        if ($quota <= 0 || $period <= 0) {
            return null;
        }

        return max(1, intdiv($quota, $period));
    }

    private function cgroupMemoryBytes(): ?int
    {
        $v2 = $this->read('/sys/fs/cgroup/memory.max');
        if ($v2 !== null && $v2 !== 'max' && ctype_digit($v2)) {
            return self::reasonableMemoryLimit((int) $v2);
        }

        $v1 = $this->read('/sys/fs/cgroup/memory/memory.limit_in_bytes');
        if ($v1 !== null && ctype_digit($v1)) {
            return self::reasonableMemoryLimit((int) $v1);
        }

        return null;
    }

    private function cpusetCpuCount(): ?int
    {
        foreach ([
            '/sys/fs/cgroup/cpuset.cpus.effective',
            '/sys/fs/cgroup/cpuset.cpus',
            '/sys/fs/cgroup/cpuset/cpuset.cpus',
        ] as $path) {
            $value = $this->read($path);
            if ($value === null) {
                continue;
            }

            $count = self::parseCpuSet($value);
            if ($count !== null) {
                return $count;
            }
        }

        return null;
    }

    private function detectHostCpuCount(): int
    {
        $cpuInfo = $this->read('/proc/cpuinfo');
        if ($cpuInfo !== null) {
            $matches = preg_match_all('/^processor\s*:/m', $cpuInfo);
            if (is_int($matches) && $matches > 0) {
                return $matches;
            }
        }

        $windows = getenv('NUMBER_OF_PROCESSORS');
        if (is_string($windows) && ctype_digit($windows) && (int) $windows > 0) {
            return (int) $windows;
        }

        return 1;
    }

    private function detectHostMemoryBytes(): ?int
    {
        $memInfo = $this->read('/proc/meminfo');
        if ($memInfo === null || preg_match('/^MemTotal:\s+(\d+)\s+kB/im', $memInfo, $match) !== 1) {
            return null;
        }

        $kilobytes = (int) $match[1];
        if ($kilobytes <= 0 || $kilobytes > intdiv(PHP_INT_MAX, 1_024)) {
            return null;
        }

        return $kilobytes * 1_024;
    }

    private function quotaCpuCount(): ?int
    {
        $v2 = $this->read('/sys/fs/cgroup/cpu.max');
        if ($v2 !== null) {
            $parts = preg_split('/\s+/', trim($v2));
            if (is_array($parts) && count($parts) >= 2 && $parts[0] !== 'max'
                && ctype_digit($parts[0]) && ctype_digit($parts[1])) {
                return self::quotaToCpuCount((int) $parts[0], (int) $parts[1]);
            }
        }

        $quota = $this->read('/sys/fs/cgroup/cpu/cpu.cfs_quota_us');
        $period = $this->read('/sys/fs/cgroup/cpu/cpu.cfs_period_us');
        if ($quota !== null && $period !== null
            && preg_match('/^-?\d+$/D', $quota) === 1
            && ctype_digit($period)) {
            return self::quotaToCpuCount((int) $quota, (int) $period);
        }

        return null;
    }

    private static function reasonableMemoryLimit(int $bytes): ?int
    {
        if ($bytes <= 0 || $bytes >= 1_152_921_504_606_846_976) {
            return null;
        }

        return $bytes;
    }

    private function read(string $path): ?string
    {
        $value = ($this->reader)($path);
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
