<?php

declare(strict_types=1);

use Infocyph\Runwire\Coroutine\CoroutineScope;
use Infocyph\Runwire\Coroutine\Enum\TaskState;
use Infocyph\Runwire\Coroutine\Internal\FiberScheduler;
use Infocyph\Runwire\Coroutine\Internal\Suspension;
use Infocyph\Runwire\Coroutine\Task;
use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Supervisor\Enum\ShutdownReason;
use Infocyph\Runwire\Supervisor\Enum\WorkerRole;
use Infocyph\Runwire\Supervisor\WorkerContext;

/** @return array{WorkerContext, resource} */
function batchOWorkerContext(WorkerRole $role = WorkerRole::TASK): array
{
    [$readyParent, $readyChild] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

    return [
        new WorkerContext(
            group: 'batch-o',
            slot: 0,
            generation: 7,
            pid: posix_getpid(),
            parentPid: posix_getppid(),
            readyStream: $readyChild,
            role: $role,
        ),
        $readyParent,
    ];
}

it('cancels and drains worker-owned coroutine work before the background loop stops', function (): void {
    [$context, $readyParent] = batchOWorkerContext();
    $loop = new SelectLoop();
    $events = new ArrayObject();
    $context->attachLoop($loop, 0.25);

    $task = $context->spawnBackground(static function (CoroutineScope $scope) use ($events): void {
        try {
            $scope->sleep(60.0);
        } finally {
            $events[] = 'cleanup';
        }
    });
    $loop->defer(static function (int $id) use ($context): void {
        unset($id);
        $context->requestStop(ShutdownReason::DEPLOYMENT_RELOAD);
    });

    $loop->run();

    expect(iterator_to_array($events))->toBe(['cleanup'])
        ->and($task->state())->toBe(TaskState::CANCELLED)
        ->and($context->backgroundTaskCount())->toBe(0)
        ->and($context->backgroundDrainExpired())->toBeFalse()
        ->and($context->acceptingBackgroundWork())->toBeFalse()
        ->and($context->shutdownReason())->toBe(ShutdownReason::DEPLOYMENT_RELOAD);
    expect(static fn() => $context->spawnBackground(static fn(CoroutineScope $scope): null => null))
        ->toThrow(LogicException::class, 'Worker is stopping');

    $context->close();
    fclose($readyParent);
});

it('turns an unhandled worker coroutine failure into a fatal worker stop', function (): void {
    [$context, $readyParent] = batchOWorkerContext();
    $loop = new SelectLoop();
    $context->attachLoop($loop, 0.25);

    $task = $context->spawnBackground(static function (CoroutineScope $scope): never {
        unset($scope);

        throw new RuntimeException('background failure');
    });

    $loop->run();

    expect($task->state())->toBe(TaskState::FAILED)
        ->and($context->stopping())->toBeTrue()
        ->and($context->shutdownReason())->toBe(ShutdownReason::FATAL_RUNTIME_ERROR)
        ->and($context->backgroundTaskCount())->toBe(0)
        ->and($context->backgroundDrainExpired())->toBeFalse();

    stream_set_blocking($readyParent, false);
    expect((string) stream_get_contents($readyParent))->toContain("U\n");

    $context->close();
    fclose($readyParent);
});

it('bounds worker coroutine drain when a task cannot cooperatively wake', function (): void {
    [$context, $readyParent] = batchOWorkerContext();
    $loop = new SelectLoop();
    $context->attachLoop($loop, 0.001);

    $context->spawnBackground(static function (CoroutineScope $scope): void {
        unset($scope);
        \Fiber::suspend(new class implements Suspension {
            public function arm(FiberScheduler $scheduler, Task $task): void
            {
                unset($scheduler, $task);
            }
        });
    });
    $loop->defer(static function (int $id) use ($context): void {
        unset($id);
        $context->requestStop();
    });

    $loop->run();

    expect($context->stopping())->toBeTrue()
        ->and($context->backgroundDrainExpired())->toBeTrue()
        ->and($context->backgroundTaskCount())->toBe(1);

    $context->close();
    fclose($readyParent);
});

it('keeps worker coroutine admission scoped to background worker roles', function (): void {
    [$context, $readyParent] = batchOWorkerContext(WorkerRole::CUSTOM);
    $context->attachLoop(new SelectLoop());

    expect(static fn() => $context->spawnBackground(static fn(CoroutineScope $scope): null => null))
        ->toThrow(LogicException::class, 'Background coroutine work requires a task or service worker role.');

    $context->close();
    fclose($readyParent);
});
