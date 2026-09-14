<?php

declare(strict_types=1);

use Infocyph\Runwire\Coroutine\CoroutinePolicy;
use Infocyph\Runwire\Coroutine\CoroutineRuntime;
use Infocyph\Runwire\Coroutine\CoroutineScope;
use Infocyph\Runwire\Coroutine\Enum\TaskState;
use Infocyph\Runwire\Coroutine\Exception\ChannelClosedException;
use Infocyph\Runwire\Coroutine\Task;
use Infocyph\Runwire\Exception\CancelledException;
use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\RequestContext;
use Infocyph\Runwire\RequestDeadline;
use Infocyph\Runwire\Runtime\Enum\CancellationReason;
use Infocyph\Runwire\Supervisor\Internal\WorkerCoroutineScope;

function batchPExpectCancelled(Task $task): CancellationReason
{
    try {
        $task->await();
    } catch (CancelledException $error) {
        return $error->reason;
    }

    throw new LogicException('Expected coroutine task cancellation.');
}

it('reports bounded scheduler and request scope diagnostics without task labels', function (): void {
    $loop = new SelectLoop();
    $policy = new CoroutinePolicy(
        maxTasks: 8,
        maxReadyBacklog: 8,
        maxFutureWaiters: 8,
        maxResumesPerTick: 2,
        maxWaitersPerPrimitive: 8,
    );
    $runtime = new CoroutineRuntime($loop, $policy);
    $during = null;

    $runtime->run(function (CoroutineScope $scope) use ($runtime, &$during): void {
        $during = $runtime->diagnostics();
        $scope->spawn(static fn(): int => 1)->await();
    });

    $after = $runtime->diagnostics();
    expect($during)->not->toBeNull()
        ->and($during->activeTasks)->toBe(1)
        ->and($during->runnableTasks)->toBe(1)
        ->and($during->rootScopesActive)->toBe(1)
        ->and($during->requestScopesActive)->toBe(0)
        ->and($after->activeTasks)->toBe(0)
        ->and($after->runnableTasks)->toBe(0)
        ->and($after->suspendedTasks)->toBe(0)
        ->and($after->spawnedTotal)->toBe(2)
        ->and($after->completedTotal)->toBe(2)
        ->and($after->failedTotal)->toBe(0)
        ->and($after->cancelledTotal)->toBe(0)
        ->and($after->readyQueueDepth)->toBe(0)
        ->and($after->readyQueueMaxDepth >= 1)->toBeTrue()
        ->and($after->readyQueueMaxDepth <= $policy->maxReadyBacklog)->toBeTrue()
        ->and($after->resumesTotal >= 2)->toBeTrue()
        ->and($after->loopTimersActive)->toBe(0)
        ->and($after->loopDeferredBacklog)->toBe(0)
        ->and($after->loopReadWatchers)->toBe(0)
        ->and($after->loopWriteWatchers)->toBe(0)
        ->and($after->maxTasks)->toBe(8)
        ->and($after->maxReadyBacklog)->toBe(8)
        ->and($after->maxWaitersPerPrimitive)->toBe(8)
        ->and($after->maxResumesPerTick)->toBe(2);

    $requestRuntime = new CoroutineRuntime();
    $context = RequestContext::standalone();
    $requestDuring = null;
    $requestRuntime->runRequest($context, function (CoroutineScope $scope) use ($requestRuntime, &$requestDuring): void {
        unset($scope);
        $requestDuring = $requestRuntime->diagnostics();
    });
    $context->complete();

    expect($requestDuring)->not->toBeNull()
        ->and($requestDuring->rootScopesActive)->toBe(1)
        ->and($requestDuring->requestScopesActive)->toBe(1)
        ->and($requestRuntime->diagnostics()->requestScopesActive)->toBe(0);
});

it('reports worker background ownership through the same bounded diagnostic snapshot', function (): void {
    $loop = new SelectLoop();
    $scope = new WorkerCoroutineScope($loop, 1.0, static function (): void {});
    $task = $scope->spawn(static function (CoroutineScope $coroutines): void {
        $coroutines->yieldNow();
    });

    $before = $scope->diagnostics();
    expect($before->backgroundScopesActive)->toBe(1)
        ->and($before->backgroundTasksActive)->toBe(1)
        ->and($before->activeTasks)->toBe(1);

    $loop->run();
    $after = $scope->diagnostics();
    expect($task->state())->toBe(TaskState::COMPLETED)
        ->and($after->activeTasks)->toBe(0)
        ->and($after->backgroundTasksActive)->toBe(0)
        ->and($after->backgroundScopesActive)->toBe(1);

    $scope->close();
    expect($scope->diagnostics()->backgroundScopesActive)->toBe(0);
});

it('keeps completed work terminal against later cancellation and pending timeout', function (): void {
    $loop = new SelectLoop();
    $runtime = new CoroutineRuntime($loop);

    $result = $runtime->run(function (CoroutineScope $scope): array {
        $task = $scope->spawn(static fn(): int => 42);
        $value = $task->await();
        $task->cancel(CancellationReason::HOST_CANCELLED);

        $clock = hrtime(true);
        $now = is_int($clock) ? $clock : (int) $clock;
        $deadlineValue = $scope->withDeadline(
            new RequestDeadline($now + 1_000_000_000),
            static fn(): int => 7,
        );

        return [$value, $task->state(), $deadlineValue];
    });

    $diagnostics = $runtime->diagnostics();
    expect($result)->toBe([42, TaskState::COMPLETED, 7])
        ->and($diagnostics->cancelledTotal)->toBe(0)
        ->and($diagnostics->failedTotal)->toBe(0)
        ->and($diagnostics->activeTasks)->toBe(0)
        ->and($loop->diagnostics()->timersActive)->toBe(0);
});

it('settles future resolution versus waiter cancellation exactly once', function (): void {
    $runtime = new CoroutineRuntime();
    $task = null;

    $runtime->run(function (CoroutineScope $scope) use (&$task): void {
        $deferred = $scope->deferred();
        $task = $scope->spawn(static fn(): mixed => $deferred->future()->await());
        $scope->yieldNow();

        $deferred->resolve('resolved-first');
        $task->cancel(CancellationReason::HOST_CANCELLED);

        expect(batchPExpectCancelled($task))->toBe(CancellationReason::HOST_CANCELLED);
    });

    $diagnostics = $runtime->diagnostics();
    expect($task)->not->toBeNull()
        ->and($task->state())->toBe(TaskState::CANCELLED)
        ->and($diagnostics->spawnedTotal)->toBe(2)
        ->and($diagnostics->completedTotal)->toBe(1)
        ->and($diagnostics->cancelledTotal)->toBe(1)
        ->and($diagnostics->failedTotal)->toBe(0)
        ->and($diagnostics->activeTasks)->toBe(0);
});

it('settles cancellation before pending timer and stream readiness without double resume', function (): void {
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    if ($pair === false) {
        throw new RuntimeException('Unable to create local stream pair.');
    }

    [$left, $right] = $pair;
    stream_set_blocking($left, false);
    stream_set_blocking($right, false);
    $loop = new SelectLoop();
    $runtime = new CoroutineRuntime($loop);

    try {
        $states = $runtime->run(function (CoroutineScope $scope) use ($left, $right): array {
            $timer = $scope->spawn(static function () use ($scope): void {
                $scope->sleep(60.0);
            });
            $reader = $scope->spawn(static function () use ($scope, $left): void {
                $scope->waitReadable($left);
            });
            $writer = $scope->spawn(static function () use ($scope, $right): void {
                $scope->waitWritable($right);
            });
            $scope->yieldNow();

            foreach ([$timer, $reader, $writer] as $task) {
                $task->cancel(CancellationReason::HOST_CANCELLED);
            }
            foreach ([$timer, $reader, $writer] as $task) {
                expect(batchPExpectCancelled($task))->toBe(CancellationReason::HOST_CANCELLED);
            }

            return [$timer->state(), $reader->state(), $writer->state()];
        });

        $loopDiagnostics = $loop->diagnostics();
        $runtimeDiagnostics = $runtime->diagnostics();
        expect($states)->toBe([TaskState::CANCELLED, TaskState::CANCELLED, TaskState::CANCELLED])
            ->and($runtimeDiagnostics->cancelledTotal)->toBe(3)
            ->and($runtimeDiagnostics->activeTasks)->toBe(0)
            ->and($loopDiagnostics->timersActive)->toBe(0)
            ->and($loopDiagnostics->readWatchers)->toBe(0)
            ->and($loopDiagnostics->writeWatchers)->toBe(0);
    } finally {
        fclose($left);
        fclose($right);
    }
});

it('settles channel close against blocked send and receive exactly once', function (): void {
    $runtime = new CoroutineRuntime();

    $states = $runtime->run(function (CoroutineScope $scope): array {
        $receiveChannel = $scope->channel();
        $sendChannel = $scope->channel(1);
        $sendChannel->send('buffered');
        $receiver = $scope->spawn(static fn(): mixed => $receiveChannel->receive());
        $sender = $scope->spawn(static function () use ($sendChannel): void {
            $sendChannel->send('blocked');
        });
        $scope->yieldNow();

        $receiveChannel->close();
        $sendChannel->close();

        expect(static fn() => $receiver->await())->toThrow(ChannelClosedException::class)
            ->and(static fn() => $sender->await())->toThrow(ChannelClosedException::class)
            ->and($sendChannel->receive())->toBe('buffered')
            ->and(static fn() => $sendChannel->receive())->toThrow(ChannelClosedException::class);

        return [$receiver->state(), $sender->state()];
    });

    $diagnostics = $runtime->diagnostics();
    expect($states)->toBe([TaskState::FAILED, TaskState::FAILED])
        ->and($diagnostics->failedTotal)->toBe(2)
        ->and($diagnostics->activeTasks)->toBe(0);
});

it('drains suspended request children before the request root leaves the scheduler', function (): void {
    $loop = new SelectLoop();
    $runtime = new CoroutineRuntime($loop);
    $context = RequestContext::standalone();
    $caught = null;

    try {
        $runtime->runRequest($context, function (CoroutineScope $scope) use ($context): void {
            $scope->spawn(static function () use ($scope): void {
                $scope->sleep(60.0);
            });
            $scope->yieldNow();
            $context->cancel(CancellationReason::HOST_CANCELLED);
        });
    } catch (CancelledException $error) {
        $caught = $error;
    } finally {
        $context->complete();
    }

    $diagnostics = $runtime->diagnostics();
    expect($caught)->not->toBeNull()
        ->and($caught->reason)->toBe(CancellationReason::HOST_CANCELLED)
        ->and($runtime->activeTaskCount())->toBe(0)
        ->and($diagnostics->requestScopesActive)->toBe(0)
        ->and($loop->diagnostics()->timersActive)->toBe(0);
});

it('drains sibling failure storms and cleanup exceptions without retaining tasks', function (): void {
    $loop = new SelectLoop();
    $runtime = new CoroutineRuntime($loop);
    $cleaned = false;
    $caught = null;

    try {
        $runtime->run(function (CoroutineScope $scope) use (&$cleaned): void {
            for ($index = 0; $index < 16; ++$index) {
                $scope->spawn(static function () use ($index): never {
                    throw new RuntimeException('storm-' . $index);
                });
            }
            $scope->spawn(static function () use ($scope, &$cleaned): void {
                try {
                    $scope->sleep(60.0);
                } finally {
                    $cleaned = true;
                    throw new LogicException('cleanup-failure');
                }
            });
        });
    } catch (Throwable $error) {
        $caught = $error;
    }

    $diagnostics = $runtime->diagnostics();
    expect($caught)->toBeInstanceOf(RuntimeException::class)
        ->and($cleaned)->toBeTrue()
        ->and($runtime->activeTaskCount())->toBe(0)
        ->and($diagnostics->failedTotal >= 2)->toBeTrue()
        ->and($diagnostics->activeTasks)->toBe(0)
        ->and($loop->diagnostics()->timersActive)->toBe(0);
});
