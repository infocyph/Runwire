<?php

declare(strict_types=1);

use Infocyph\Runwire\CancellationSource;
use Infocyph\Runwire\Coroutine\CoroutinePolicy;
use Infocyph\Runwire\Coroutine\CoroutineRuntime;
use Infocyph\Runwire\Coroutine\CoroutineScope;
use Infocyph\Runwire\Coroutine\Exception\CoroutineOverflowException;
use Infocyph\Runwire\Runtime\Enum\CancellationReason;

it('cleans child cancellation ownership when task admission is rejected', function (): void {
    $runtime = new CoroutineRuntime(policy: new CoroutinePolicy(maxTasks: 1, maxReadyBacklog: 1));

    $subscriptionCount = $runtime->run(function (CoroutineScope $scope): int {
        for ($attempt = 0; $attempt < 128; ++$attempt) {
            try {
                $scope->spawn(static function (): void {});
                test()->fail('Expected child task admission to be rejected.');
            } catch (CoroutineOverflowException) {
                // The root task consumes the only task slot; rejected child sources must be disposed.
            }
        }

        return $scope->cancellation()->subscriptionCount();
    });

    expect($subscriptionCount)->toBe(0)
        ->and($runtime->activeTaskCount())->toBe(0);
});

it('keeps cancellation observers registered when unsubscribe handles are discarded', function (): void {
    $source = new CancellationSource();
    $called = 0;
    $source->token()->onCancel(static function () use (&$called): void {
        ++$called;
    });

    gc_collect_cycles();

    expect($source->token()->subscriptionCount())->toBe(1)
        ->and($source->cancel(CancellationReason::HOST_CANCELLED))->toBeTrue()
        ->and($called)->toBe(1)
        ->and($source->token()->subscriptionCount())->toBe(0);
});


it('settles suspended task cancellation and concurrent future completion only once under repeated races', function (): void {
    $loop = new \Infocyph\Runwire\Loop\SelectLoop();
    $runtime = new CoroutineRuntime($loop);
    $states = $runtime->run(static function (CoroutineScope $scope): array {
        $states = [];

        for ($i = 0; $i < 64; ++$i) {
            $deferred = $scope->deferred();
            $future = $deferred->future();
            $task = $scope->spawn(static fn(): mixed => $future->await());
            $scope->yieldNow();

            if (($i & 1) === 0) {
                $task->cancel(CancellationReason::HOST_CANCELLED);
                $deferred->resolve($i);
            } else {
                $deferred->resolve($i);
                $task->cancel(CancellationReason::HOST_CANCELLED);
            }

            expect(fn() => $task->await())->toThrow(\Infocyph\Runwire\Exception\CancelledException::class)
                ->and($future->result())->toBe($i)
                ->and(fn() => $deferred->resolve(-1))->toThrow(
                    \Infocyph\Runwire\Coroutine\Exception\FutureCompletedException::class,
                );

            $states[] = $task->state();
        }

        return $states;
    });

    expect($states)->toHaveCount(64);
    foreach ($states as $state) {
        expect($state)->toBe(\Infocyph\Runwire\Coroutine\Enum\TaskState::CANCELLED);
    }
    expect($runtime->activeTaskCount())->toBe(0)
        ->and($loop->diagnostics()->timersActive)->toBe(0)
        ->and($loop->diagnostics()->readWatchers)->toBe(0)
        ->and($loop->diagnostics()->writeWatchers)->toBe(0)
        ->and($loop->diagnostics()->deferredBacklog)->toBe(0);
});


it('preserves task-local inheritance through barrier, mutex and bounded channel cooperation', function (): void {
    $runtime = new CoroutineRuntime();
    $result = $runtime->run(static function (CoroutineScope $scope): array {
        $local = new \Infocyph\Runwire\Coroutine\TaskLocal();
        $scope->setLocal($local, 'request');
        $barrier = $scope->barrier(2);
        $mutex = $scope->mutex();
        $channel = $scope->channel(1);

        $child = $scope->spawn(static function () use ($scope, $local, $barrier, $mutex, $channel): array {
            $original = $scope->local($local);
            $generation = $barrier->wait();
            $mutex->synchronized(static function () use ($channel): void {
                $channel->send('shared-core');
            });
            $scope->setLocal($local, 'child');

            return [$original, $generation, $scope->local($local)];
        });

        $generation = $barrier->wait();
        $message = $channel->receive();
        $childResult = $child->await();

        return [$generation, $message, $childResult, $scope->local($local)];
    });

    expect($result)->toBe([0, 'shared-core', ['request', 0, 'child'], 'request'])
        ->and($runtime->activeTaskCount())->toBe(0);
});
