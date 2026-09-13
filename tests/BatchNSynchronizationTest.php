<?php

declare(strict_types=1);

use Infocyph\Runwire\Coroutine\CoroutinePolicy;
use Infocyph\Runwire\Coroutine\CoroutineRuntime;
use Infocyph\Runwire\Coroutine\CoroutineScope;
use Infocyph\Runwire\Coroutine\Exception\BarrierBrokenException;
use Infocyph\Runwire\Coroutine\Exception\ChannelClosedException;
use Infocyph\Runwire\Coroutine\Exception\CoroutineOverflowException;
use Infocyph\Runwire\Coroutine\Exception\SynchronizationException;
use Infocyph\Runwire\Coroutine\Task;
use Infocyph\Runwire\Exception\CancelledException;
use Infocyph\Runwire\Runtime\Enum\CancellationReason;

it('performs FIFO rendezvous handoff without reserving payload sentinels', function (): void {
    $runtime = new CoroutineRuntime();

    $result = $runtime->run(function (CoroutineScope $scope): array {
        $channel = $scope->channel();
        $first = $scope->spawn(function () use ($channel): string {
            $channel->send(false);

            return 'first';
        });
        $second = $scope->spawn(function () use ($channel): string {
            $channel->send(null);

            return 'second';
        });
        $scope->yieldNow();

        return [
            $channel->receive(),
            $channel->receive(),
            $first->await(),
            $second->await(),
        ];
    });

    expect($result)->toBe([false, null, 'first', 'second'])
        ->and($runtime->activeTaskCount())->toBe(0);
});

it('drains buffered channel values after close and then exposes closed state', function (): void {
    $runtime = new CoroutineRuntime();

    $runtime->run(function (CoroutineScope $scope): void {
        $channel = $scope->channel(2);
        $channel->send(0);
        $channel->send('');

        expect($channel->size())->toBe(2)
            ->and($channel->close())->toBeTrue()
            ->and($channel->close())->toBeFalse()
            ->and($channel->receive())->toBe(0)
            ->and($channel->receive())->toBe('')
            ->and(fn() => $channel->receive())->toThrow(ChannelClosedException::class)
            ->and(fn() => $channel->send('late'))->toThrow(ChannelClosedException::class);
    });
});

it('removes cancelled channel waiters before the next committed handoff', function (): void {
    $runtime = new CoroutineRuntime();

    $value = $runtime->run(function (CoroutineScope $scope): string {
        $channel = $scope->channel();
        $cancelled = $scope->spawn(fn(): mixed => $channel->receive());
        $scope->yieldNow();
        $cancelled->cancel(CancellationReason::HOST_CANCELLED);

        try {
            $cancelled->await();
        } catch (CancelledException) {
        }

        $sender = $scope->spawn(function () use ($channel): void {
            $channel->send('survived');
        });
        $scope->yieldNow();
        $result = $channel->receive();
        $sender->await();

        return $result;
    });

    expect($value)->toBe('survived');
});

it('enforces the configured waiter bound across primitive suspension', function (): void {
    $runtime = new CoroutineRuntime(policy: new CoroutinePolicy(
        maxTasks: 4,
        maxReadyBacklog: 4,
        maxWaitersPerPrimitive: 1,
    ));

    $runtime->run(function (CoroutineScope $scope): void {
        $channel = $scope->channel();
        $first = $scope->spawn(fn(): mixed => $channel->receive());
        $scope->yieldNow();

        expect(fn() => $channel->receive())->toThrow(CoroutineOverflowException::class);
        $first->cancel();

        try {
            $first->await();
        } catch (CancelledException) {
        }
    });
});

it('grants semaphore permits FIFO and restores permits after exceptions', function (): void {
    $runtime = new CoroutineRuntime();

    $result = $runtime->run(function (CoroutineScope $scope): array {
        $semaphore = $scope->semaphore(1);
        $order = [];
        $semaphore->acquire();
        $first = $scope->spawn(function () use ($semaphore, &$order): void {
            $semaphore->acquire();
            $order[] = 'first';
            $semaphore->release();
        });
        $second = $scope->spawn(function () use ($semaphore, &$order): void {
            $semaphore->acquire();
            $order[] = 'second';
            $semaphore->release();
        });
        $scope->yieldNow();
        $semaphore->release();
        $first->await();
        $second->await();

        try {
            $semaphore->withPermit(static function (): void {
                throw new RuntimeException('inside-permit');
            });
        } catch (RuntimeException $error) {
            expect($error->getMessage())->toBe('inside-permit');
        }

        expect(fn() => $semaphore->release())->toThrow(SynchronizationException::class);

        return [$order, $semaphore->availablePermits()];
    });

    expect($result)->toBe([['first', 'second'], 1]);
});

it('skips a cancelled semaphore waiter without leaking its permit', function (): void {
    $runtime = new CoroutineRuntime();

    $winner = $runtime->run(function (CoroutineScope $scope): string {
        $semaphore = $scope->semaphore(1);
        $semaphore->acquire();
        $cancelled = $scope->spawn(function () use ($semaphore): void {
            $semaphore->acquire();
            $semaphore->release();
        });
        $winner = $scope->spawn(function () use ($semaphore): string {
            $semaphore->acquire();

            try {
                return 'winner';
            } finally {
                $semaphore->release();
            }
        });
        $scope->yieldNow();
        $cancelled->cancel();
        $semaphore->release();

        try {
            $cancelled->await();
        } catch (CancelledException) {
        }

        $result = $winner->await();
        expect($semaphore->availablePermits())->toBe(1);

        return $result;
    });

    expect($winner)->toBe('winner');
});

it('enforces mutex ownership FIFO and non-recursive locking', function (): void {
    $runtime = new CoroutineRuntime();

    $order = $runtime->run(function (CoroutineScope $scope): array {
        $mutex = $scope->mutex();
        $order = [];
        $mutex->lock();
        expect(fn() => $mutex->lock())->toThrow(SynchronizationException::class);

        $intruder = $scope->spawn(function () use ($mutex): void {
            expect(fn() => $mutex->unlock())->toThrow(SynchronizationException::class);
        });
        $first = $scope->spawn(function () use ($mutex, &$order): void {
            $mutex->lock();
            $order[] = 'first';
            $mutex->unlock();
        });
        $second = $scope->spawn(function () use ($mutex, &$order): void {
            $mutex->lock();
            $order[] = 'second';
            $mutex->unlock();
        });
        $scope->yieldNow();
        $intruder->await();
        $mutex->unlock();
        $first->await();
        $second->await();

        return $order;
    });

    expect($order)->toBe(['first', 'second']);
});

it('skips cancelled mutex waiters and transfers ownership to the next live task', function (): void {
    $runtime = new CoroutineRuntime();

    $result = $runtime->run(function (CoroutineScope $scope): string {
        $mutex = $scope->mutex();
        $mutex->lock();
        $cancelled = $scope->spawn(function () use ($mutex): void {
            $mutex->lock();
            $mutex->unlock();
        });
        $winner = $scope->spawn(fn(): string => $mutex->synchronized(static fn(): string => 'locked'));
        $scope->yieldNow();
        $cancelled->cancel();
        $mutex->unlock();

        try {
            $cancelled->await();
        } catch (CancelledException) {
        }

        return $winner->await();
    });

    expect($result)->toBe('locked');
});

it('reuses explicit barrier generations without destructor synchronization', function (): void {
    $runtime = new CoroutineRuntime();

    $rounds = $runtime->run(function (CoroutineScope $scope): array {
        $barrier = $scope->barrier(3);
        $tasks = [];
        for ($index = 0; $index < 3; ++$index) {
            $tasks[] = $scope->spawn(static function () use ($barrier): array {
                return [$barrier->wait(), $barrier->wait()];
            });
        }

        return array_map(static fn(Task $task): mixed => $task->await(), $tasks);
    });

    expect($rounds)->toBe([[0, 1], [0, 1], [0, 1]]);
});

it('breaks a barrier generation when one waiting party is cancelled and remains reusable', function (): void {
    $runtime = new CoroutineRuntime();

    $generation = $runtime->run(function (CoroutineScope $scope): int {
        $barrier = $scope->barrier(3);
        $cancelled = $scope->spawn(fn(): int => $barrier->wait());
        $peer = $scope->spawn(fn(): int => $barrier->wait());
        $scope->yieldNow();
        $cancelled->cancel();

        try {
            $cancelled->await();
        } catch (CancelledException) {
        }
        expect(fn() => $peer->await())->toThrow(BarrierBrokenException::class);

        $tasks = [];
        for ($index = 0; $index < 3; ++$index) {
            $tasks[] = $scope->spawn(fn(): int => $barrier->wait());
        }
        $values = array_map(static fn(Task $task): mixed => $task->await(), $tasks);
        expect($values)->toBe([1, 1, 1]);

        return $barrier->generation();
    });

    expect($generation)->toBe(2)
        ->and($runtime->activeTaskCount())->toBe(0);
});
