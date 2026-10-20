<?php

declare(strict_types=1);

use Infocyph\Runwire\Coroutine\CoroutineScope;
use Infocyph\Runwire\Coroutine\Enum\TaskState;
use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Supervisor\Enum\ShutdownReason;
use Infocyph\Runwire\Supervisor\Enum\WorkerRole;
use Infocyph\Runwire\Supervisor\WorkerContext;

it('invalidates background admission and periodic handles when a worker generation closes', function (): void {
    $loop = new SelectLoop();
    $worker = new WorkerContext(
        group: 'generation-isolation',
        slot: 0,
        generation: 1,
        pid: getmypid(),
        parentPid: 0,
        role: WorkerRole::TASK,
    );
    $worker->attachLoop($loop);

    $calls = 0;
    $oldHandle = $worker->every('heartbeat', 0.01, static function () use (&$calls): void {
        ++$calls;
    });

    expect($worker->acceptingBackgroundWork())->toBeTrue();
    $worker->close();
    $worker->close();

    expect($worker->stopping())->toBeTrue()
        ->and($worker->acceptingBackgroundWork())->toBeFalse()
        ->and($worker->backgroundTaskCount())->toBe(0)
        ->and($oldHandle->cancel())->toBeFalse()
        ->and($loop->diagnostics()->timersActive)->toBe(0)
        ->and($loop->diagnostics()->readWatchers)->toBe(0)
        ->and($loop->diagnostics()->writeWatchers)->toBe(0)
        ->and($calls)->toBe(0);

    expect(fn() => $worker->spawnBackground(static function (): void {}))
        ->toThrow(LogicException::class);
    expect(fn() => $worker->every('late-task', 0.01, static function (): void {}))
        ->toThrow(LogicException::class);
});

it('isolates cancelled coroutine and periodic work from a replacement worker generation', function (): void {
    $priorTickCount = 0;
    $replacementTickCount = 0;
    $oldLoop = new SelectLoop();
    $old = new WorkerContext(
        group: 'generation-isolation',
        slot: 0,
        generation: 1,
        pid: getmypid(),
        parentPid: 0,
        role: WorkerRole::TASK,
    );
    $old->attachLoop($oldLoop, 0.1);
    $oldHandle = $old->every('heartbeat', 0.001, static function () use (&$priorTickCount): void {
        ++$priorTickCount;
    });
    $task = $old->spawnBackground(static function (CoroutineScope $scope): void {
        $scope->sleep(60.0);
    });
    $oldLoop->delay(0.01, static function () use ($old): void {
        $old->requestStop(ShutdownReason::DEPLOYMENT_RELOAD);
    });
    try {
        $oldLoop->run();
        expect($old->stopping())->toBeTrue()
            ->and($old->acceptingBackgroundWork())->toBeFalse()
            ->and($task->state())->toBe(TaskState::CANCELLED)
            ->and($old->backgroundTaskCount())->toBe(0);
    } finally {
        $old->close();
    }

    $retiredTickCount = $priorTickCount;
    expect($oldHandle->cancel())->toBeFalse()
        ->and($oldLoop->diagnostics()->timersActive)->toBe(0);

    $newLoop = new SelectLoop();
    $replacement = new WorkerContext(
        group: 'generation-isolation',
        slot: 0,
        generation: 2,
        pid: getmypid(),
        parentPid: 0,
        role: WorkerRole::TASK,
    );
    $replacement->attachLoop($newLoop);
    $newHandle = $replacement->every('heartbeat', 0.001, static function () use (&$replacementTickCount): void {
        ++$replacementTickCount;
    });
    $newLoop->delay(0.02, static function () use ($replacement): void {
        $replacement->requestStop(ShutdownReason::DEPLOYMENT_RELOAD);
    });

    try {
        $newLoop->run();

        expect($replacement->generation)->toBe(2)
            ->and($replacementTickCount)->toBeGreaterThan(0)
            ->and($priorTickCount)->toBe($retiredTickCount)
            ->and($newHandle->cancel())->toBeTrue()
            ->and($replacement->acceptingBackgroundWork())->toBeFalse();
    } finally {
        $replacement->close();
    }

    expect($newLoop->diagnostics()->timersActive)->toBe(0);
});
