<?php

declare(strict_types=1);

use Infocyph\Runwire\Runtime\Internal\SystemResourceProbe;

it('respects the lowest host, quota and effective cpuset limits', function (): void {
    $cgroup = [
        '/sys/fs/cgroup/cpu.max' => '250000 100000',
        '/sys/fs/cgroup/cpuset.cpus.effective' => '0-3,6-8',
        '/sys/fs/cgroup/memory.max' => '268435456',
    ];
    $probe = new SystemResourceProbe(
        static fn(string $path): ?string => $cgroup[$path] ?? null,
        hostCpuCount: 16,
        hostMemoryBytes: 2_147_483_648,
    );

    expect($probe->probe()->effectiveCpuCount)->toBe(2)
        ->and($probe->probe()->effectiveMemoryBytes)->toBe(268_435_456);
});

it('conservatively handles fractional CPU quotas without inflating worker capacity', function (): void {
    $probe = new SystemResourceProbe(
        static fn(string $path): ?string => $path === '/sys/fs/cgroup/cpu.max' ? '150000 100000' : null,
        hostCpuCount: 8,
    );

    expect($probe->probe()->effectiveCpuCount)->toBe(1);
});

it('does not overstate container CPUs for malformed or overlapping cpusets', function (): void {
    foreach (['0-3,2-5', '3-1', '0-3,0', '0-', '9999999999999999999999999', '0-1000001'] as $value) {
        $probe = new SystemResourceProbe(
            static fn(string $path): ?string => $path === '/sys/fs/cgroup/cpuset.cpus.effective' ? $value : null,
            hostCpuCount: 32,
        );
        expect($probe->probe()->effectiveCpuCount)->toBe(1);
    }
});

it('keeps valid sparse cpusets bounded without double counting', function (): void {
    $probe = new SystemResourceProbe(
        static fn(string $path): ?string => $path === '/sys/fs/cgroup/cpuset.cpus.effective' ? '0-1,4,8-10' : null,
        hostCpuCount: 32,
    );

    expect($probe->probe()->effectiveCpuCount)->toBe(6);
});
