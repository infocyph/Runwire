<?php

declare(strict_types=1);

use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Supervisor\AutoscalePolicy;
use Infocyph\Runwire\Supervisor\Supervisor;
use Infocyph\Runwire\Supervisor\WorkerGroup;

it('resizes real native worker slots after sustained windows and drains scaled-in children', function (): void {
    expect(function_exists('pcntl_fork'))->toBeTrue();

    $loop = new SelectLoop();
    $samples = 0;
    $supervisor = new Supervisor($loop);
    $supervisor->group(new WorkerGroup('adaptive-workers', 2, static function ($context): void {
        $context->ready();
        while (!$context->stopping()) {
            usleep(20_000);
        }
    }, automaticReady: false));
    $supervisor->autoscale(
        'adaptive-workers',
        new AutoscalePolicy(
            minWorkers: 1,
            maxWorkers: 2,
            scaleOutQueueDepth: 1,
            sampleSeconds: 1.0,
            cooldownSeconds: 1.0,
        ),
        static function () use (&$samples): array {
            ++$samples;

            return [
                'cpuRatio' => $samples <= 2 ? 0.01 : 0.9,
                'queued' => $samples <= 2 ? 0 : 2,
                'successfulRps' => $samples <= 2 ? 0.0 : 10.0,
                'p99Milliseconds' => 1.0,
            ];
        },
    );

    $observedCounts = [];
    $overlappingSlots = 0;
    $loop->repeat(0.15, static function () use ($supervisor, &$observedCounts, &$overlappingSlots): void {
        $status = $supervisor->status();
        $observedCounts[] = count(array_filter($status->workers, static fn($worker): bool => $worker->current));
        $slots = [];
        foreach ($status->workers as $worker) {
            if (isset($slots[$worker->slot])) {
                ++$overlappingSlots;
            }
            $slots[$worker->slot] = true;
        }
    });
    $loop->delay(6.2, static fn() => $supervisor->stop());
    $supervisor->run();

    expect($samples)->toBeGreaterThanOrEqual(4)
        ->and($observedCounts)->toContain(1)
        ->and($observedCounts)->toContain(2)
        ->and($overlappingSlots)->toBe(0)
        ->and($supervisor->status()->workers)->toBe([]);
});
