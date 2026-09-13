<?php

declare(strict_types=1);

use Infocyph\Runwire\Coroutine\CoroutineRuntime;
use Infocyph\Runwire\Coroutine\CoroutineScope;
use Infocyph\Runwire\Coroutine\Task;
use Infocyph\Runwire\Coroutine\TaskLocal;
use Infocyph\Runwire\Exception\CancelledException;
use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\RequestContext;
use Infocyph\Runwire\RequestDeadline;
use Infocyph\Runwire\Runtime\Enum\CancellationReason;
use Infocyph\Runwire\Supervisor\Enum\ShutdownReason;
use Infocyph\Runwire\Supervisor\Enum\WorkerRole;
use Infocyph\Runwire\Supervisor\WorkerContext;

/** @return array{WorkerContext, resource} */
function batchPWorkerContext(int $generation): array
{
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    if ($pair === false) {
        throw new RuntimeException('Unable to create worker lifecycle stream pair.');
    }

    [$readyParent, $readyChild] = $pair;

    return [
        new WorkerContext(
            group: 'batch-p',
            slot: 0,
            generation: $generation,
            pid: posix_getpid(),
            parentPid: posix_getppid(),
            readyStream: $readyChild,
            role: WorkerRole::TASK,
        ),
        $readyParent,
    ];
}

it('keeps scheduler state and loop resources bounded across mixed coroutine churn', function (): void {
    $loop = new SelectLoop();
    $runtime = new CoroutineRuntime($loop);
    $weakPayloads = [];
    $timeoutCount = 0;

    $sum = $runtime->run(function (CoroutineScope $scope) use (&$timeoutCount, &$weakPayloads): int {
        $sum = 0;

        for ($index = 0; $index < 256; ++$index) {
            $payload = new stdClass();
            $payload->value = $index;
            $weakPayloads[] = WeakReference::create($payload);
            $task = $scope->spawn(static fn(): int => $payload->value);
            unset($payload);
            $sum += $task->await();
        }

        for ($round = 0; $round < 16; ++$round) {
            $tasks = [];
            for ($index = 0; $index < 16; ++$index) {
                $tasks[] = $scope->spawn(static function () use ($scope): int {
                    $scope->yieldNow();

                    return 1;
                });
            }
            foreach ($tasks as $task) {
                $sum += $task->await();
            }
        }

        $channel = $scope->channel(16);
        $producer = $scope->spawn(static function () use ($channel): void {
            for ($value = 0; $value < 512; ++$value) {
                $channel->send($value);
            }
            $channel->close();
        });
        $consumer = $scope->spawn(static function () use ($channel): int {
            $channelSum = 0;
            while (true) {
                try {
                    $channelSum += $channel->receive();
                } catch (\Infocyph\Runwire\Coroutine\Exception\ChannelClosedException) {
                    return $channelSum;
                }
            }
        });
        $producer->await();
        $sum += $consumer->await();

        for ($round = 0; $round < 32; ++$round) {
            $sum += $scope->group(static function (CoroutineScope $group): int {
                $tasks = [];
                for ($index = 0; $index < 4; ++$index) {
                    $tasks[] = $group->spawn(static fn(): int => 1);
                }

                return array_sum(array_map(static fn(Task $task): int => $task->await(), $tasks));
            });
        }

        $cancelled = [];
        for ($index = 0; $index < 32; ++$index) {
            $cancelled[] = $scope->spawn(static function () use ($scope): void {
                $scope->sleep(60.0);
            });
        }
        $scope->yieldNow();
        foreach ($cancelled as $task) {
            $task->cancel(CancellationReason::HOST_CANCELLED);
        }
        foreach ($cancelled as $task) {
            try {
                $task->await();
            } catch (CancelledException $error) {
                expect($error->reason)->toBe(CancellationReason::HOST_CANCELLED);
            }
        }

        for ($index = 0; $index < 4; ++$index) {
            $clock = hrtime(true);
            $now = is_int($clock) ? $clock : (int) $clock;
            try {
                $scope->withDeadline(
                    new RequestDeadline($now + 1_000_000),
                    static function (CoroutineScope $deadlineScope): void {
                        $deadlineScope->sleep(60.0);
                    },
                );
            } catch (CancelledException $error) {
                expect($error->reason)->toBe(CancellationReason::DEADLINE_EXCEEDED);
                ++$timeoutCount;
            }
        }

        return $sum;
    });

    gc_collect_cycles();
    $diagnostics = $runtime->diagnostics();
    $loopDiagnostics = $loop->diagnostics();

    expect($sum > 0)->toBeTrue()
        ->and($timeoutCount)->toBe(4)
        ->and($diagnostics->spawnedTotal > 500)->toBeTrue()
        ->and($diagnostics->activeTasks)->toBe(0)
        ->and($diagnostics->readyQueueDepth)->toBe(0)
        ->and($diagnostics->completedTotal + $diagnostics->failedTotal + $diagnostics->cancelledTotal)
        ->toBe($diagnostics->spawnedTotal)
        ->and($loopDiagnostics->timersActive)->toBe(0)
        ->and($loopDiagnostics->deferredBacklog)->toBe(0)
        ->and($loopDiagnostics->readWatchers)->toBe(0)
        ->and($loopDiagnostics->writeWatchers)->toBe(0);

    foreach ($weakPayloads as $weakPayload) {
        expect($weakPayload->get())->toBeNull();
    }
});

it('isolates repeated request roots and releases request task-local payloads', function (): void {
    $loop = new SelectLoop();
    $runtime = new CoroutineRuntime($loop);
    $weakPayloads = [];

    for ($iteration = 0; $iteration < 32; ++$iteration) {
        $context = RequestContext::standalone();
        $payload = new stdClass();
        $payload->iteration = $iteration;
        $weakPayloads[] = WeakReference::create($payload);

        $result = $runtime->runRequest(
            $context,
            static function (CoroutineScope $scope) use ($payload): int {
                $local = new TaskLocal();
                $scope->setLocal($local, $payload);
                $child = $scope->spawn(static function () use ($scope, $local): int {
                    $scope->yieldNow();

                    return $scope->local($local)->iteration;
                });

                return $child->await();
            },
        );
        unset($payload);
        $context->complete();

        expect($result)->toBe($iteration)
            ->and($runtime->activeTaskCount())->toBe(0)
            ->and($runtime->diagnostics()->requestScopesActive)->toBe(0)
            ->and($context->cancellation->subscriptionCount())->toBe(0);
    }

    gc_collect_cycles();
    $loopDiagnostics = $loop->diagnostics();
    expect($loopDiagnostics->timersActive)->toBe(0)
        ->and($loopDiagnostics->deferredBacklog)->toBe(0)
        ->and($loopDiagnostics->readWatchers)->toBe(0)
        ->and($loopDiagnostics->writeWatchers)->toBe(0);

    foreach ($weakPayloads as $weakPayload) {
        expect($weakPayload->get())->toBeNull();
    }
});

it('drains suspended background work across repeated worker generations', function (): void {
    for ($generation = 1; $generation <= 8; ++$generation) {
        [$context, $readyParent] = batchPWorkerContext($generation);
        $loop = new SelectLoop();
        $context->attachLoop($loop, 0.05);
        $tasks = [];

        for ($index = 0; $index < 8; ++$index) {
            $tasks[] = $context->spawnBackground(static function (CoroutineScope $scope): void {
                $scope->sleep(60.0);
            });
        }
        $loop->defer(static function (int $id) use ($context): void {
            unset($id);
            $context->requestStop(ShutdownReason::DEPLOYMENT_RELOAD);
        });

        $loop->run();

        expect($context->backgroundTaskCount())->toBe(0)
            ->and($context->backgroundDrainExpired())->toBeFalse()
            ->and($context->acceptingBackgroundWork())->toBeFalse()
            ->and($loop->diagnostics()->timersActive)->toBe(0);
        foreach ($tasks as $task) {
            expect($task->state())->toBe(\Infocyph\Runwire\Coroutine\Enum\TaskState::CANCELLED);
        }

        $context->close();
        fclose($readyParent);
    }
});
