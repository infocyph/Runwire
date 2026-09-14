<?php

declare(strict_types=1);

use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Supervisor\Enum\SupervisorEventType;
use Infocyph\Runwire\Supervisor\RestartPolicy;
use Infocyph\Runwire\Supervisor\Supervisor;
use Infocyph\Runwire\Supervisor\SupervisorEvent;
use Infocyph\Runwire\Supervisor\WorkerContext;
use Infocyph\Runwire\Supervisor\WorkerGroup;

it('recycles one worker after replacement readiness and restores normal restart ownership', function (): void {
    $loop = new SelectLoop();
    $supervisor = new Supervisor($loop);
    $events = [];
    $readyPids = [];
    $recycleResults = [];
    $statusDuring = null;

    $supervisor->onEvent(static function (SupervisorEvent $event) use (&$events, &$readyPids, &$recycleResults, &$statusDuring, $supervisor, $loop): void {
        $events[] = $event->type;
        if ($event->type !== SupervisorEventType::WORKER_READY || $event->pid === null) {
            return;
        }
        $readyPids[] = $event->pid;
        $readyCount = count($readyPids);
        if ($readyCount === 1) {
            $loop->delay(0.01, static function () use (&$recycleResults, &$statusDuring, $supervisor): void {
                $statusDuring = $supervisor->status();
                $recycleResults[] = $supervisor->recycle('workers', 0);
                $recycleResults[] = $supervisor->recycle('workers', 0);
            });
            return;
        }
        if ($readyCount === 2) {
            $pid = $event->pid;
            $loop->delay(0.04, static function () use ($pid): void { posix_kill($pid, SIGKILL); });
            return;
        }
        if ($readyCount === 3) {
            $loop->delay(0.02, static function () use ($supervisor): void { $supervisor->stop(); });
        }
    });

    $supervisor->group(WorkerGroup::callbacks(
        name: 'workers',
        count: 1,
        factory: static function (WorkerContext $context): void {
            $stop = $context->stopStream();
            while (!$context->stopping()) {
                $read = [$stop];
                $write = [];
                $except = [];
                stream_select($read, $write, $except, 1, 0);
                $context->consumeStopWake();
            }
        },
        restartPolicy: new RestartPolicy(maxRestarts: 3, windowSeconds: 2.0, initialBackoffSeconds: 0.001, maxBackoffSeconds: 0.01),
        shutdownTimeoutSeconds: 0.2,
    ));
    $loop->delay(1.0, static function () use ($supervisor): void { $supervisor->stop(true); });
    $supervisor->run();

    expect($recycleResults)->toBe([true, false])
        ->and(count(array_unique($readyPids)))->toBeGreaterThanOrEqual(3)
        ->and($events)->toContain(SupervisorEventType::WORKER_RECYCLE_STARTED)
        ->toContain(SupervisorEventType::WORKER_STOP_REQUESTED)
        ->toContain(SupervisorEventType::WORKER_RESTART_SCHEDULED)
        ->and($statusDuring)->not->toBeNull()
        ->and($statusDuring->masterPid)->toBe(posix_getpid())
        ->and($statusDuring->workerCount)->toBe(1)
        ->and($statusDuring->currentWorkerCount)->toBe(1)
        ->and($statusDuring->readyWorkerCount)->toBe(1)
        ->and($supervisor->status()->workers)->toBe([]);
});

it('isolates lifecycle observer failures and reports their count in status', function (): void {
    $loop = new SelectLoop();
    $supervisor = new Supervisor($loop);
    $observed = [];
    $supervisor->onEvent(static function (SupervisorEvent $event) use (&$observed): void { $observed[] = $event->type; });
    $supervisor->onEvent(static function (): void { throw new RuntimeException('observer failure'); });
    $supervisor->group(WorkerGroup::callbacks(
        'events', 1,
        static function (WorkerContext $context): void { while (!$context->stopping()) { usleep(1_000); } },
        shutdownTimeoutSeconds: 0.1,
    ));
    $loop->delay(0.04, static fn () => $supervisor->stop());
    $supervisor->run();
    $status = $supervisor->status();

    expect($observed)->toContain(SupervisorEventType::SUPERVISOR_STARTING)
        ->toContain(SupervisorEventType::WORKER_SPAWNED)
        ->toContain(SupervisorEventType::WORKER_READY)
        ->toContain(SupervisorEventType::SUPERVISOR_STOPPED)
        ->and($status->lifecycleListenerFailures)->toBeGreaterThanOrEqual(1)
        ->and($status->workerCount)->toBe(0)
        ->and($status->uptimeSeconds)->toBeGreaterThan(0);
});

it('validates recycle targets without affecting running workers', function (): void {
    $supervisor = new Supervisor(new SelectLoop());
    $supervisor->group(WorkerGroup::callbacks('known', 1, static function (): void {}));
    expect(fn () => $supervisor->recycle('missing', 0))->toThrow(LogicException::class)
        ->and(fn () => $supervisor->recycle('known', 1))->toThrow(LogicException::class)
        ->and($supervisor->recycle('known', 0))->toBeFalse();
});
