<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Benchmarks;

use Infocyph\Runwire\Coroutine\CoroutineRuntime;
use Infocyph\Runwire\Coroutine\CoroutineScope;
use Infocyph\Runwire\RequestContext;
use PhpBench\Attributes\BeforeMethods;
use PhpBench\Attributes\Iterations;
use PhpBench\Attributes\Revs;
use PhpBench\Attributes\Warmup;

#[BeforeMethods('setUp')]
#[Iterations(5)]
#[Revs(100)]
#[Warmup(1)]
final class CoroutineRuntimeBench
{
    private CoroutineRuntime $runtime;

    /**
     * Initialize fixtures before PHPBench's per-iteration setup refresh.
     */
    public function __construct()
    {
        $this->setUp();
    }

    public function setUp(): void
    {
        $this->runtime = new CoroutineRuntime();
    }

    /**
     * @throws \Throwable
     */
    public function benchBufferedChannelThroughput(): int
    {
        return $this->runtime->run(static function (CoroutineScope $scope): int {
            $channel = $scope->channel(8);
            $producer = $scope->spawn(static function () use ($channel): void {
                for ($value = 1; $value <= 8; ++$value) {
                    $channel->send($value);
                }
            });
            $consumer = $scope->spawn(static function () use ($channel): int {
                $sum = 0;
                for ($index = 0; $index < 8; ++$index) {
                    $sum += self::integerResult($channel->receive());
                }

                return $sum;
            });

            $producer->await();

            return $consumer->await();
        });
    }

    /**
     * @throws \Throwable
     */
    public function benchFutureAwaitResolve(): int
    {
        return $this->runtime->run(static function (CoroutineScope $scope): int {
            $deferred = $scope->deferred();
            $waiter = $scope->spawn(static fn(): mixed => $deferred->future()->await());
            $scope->spawn(static function () use ($deferred): void {
                $deferred->resolve(1);
            });

            return self::integerResult($waiter->await());
        });
    }

    /**
     * @throws \InvalidArgumentException
     * @throws \LogicException
     */
    public function benchRequestContextWithoutCoroutine(): int
    {
        $context = RequestContext::standalone();
        $length = strlen($context->requestId);
        $context->complete();

        return $length;
    }

    /**
     * @throws \Throwable
     */
    public function benchRequestRootCoroutine(): int
    {
        $context = RequestContext::standalone();

        try {
            return $this->runtime->runRequest(
                $context,
                static function (CoroutineScope $scope): int {
                    unset($scope);

                    return 1;
                },
            );
        } finally {
            $context->complete();
        }
    }

    /**
     * @throws \Throwable
     */
    public function benchSchedulerYieldResume(): int
    {
        return $this->runtime->run(static function (CoroutineScope $scope): int {
            for ($index = 0; $index < 4; ++$index) {
                $scope->yieldNow();
            }

            return 1;
        });
    }

    /**
     * @throws \Throwable
     */
    public function benchSemaphoreAcquireRelease(): int
    {
        return $this->runtime->run(static function (CoroutineScope $scope): int {
            $semaphore = $scope->semaphore(1);
            for ($index = 0; $index < 8; ++$index) {
                $semaphore->acquire();
                $semaphore->release();
            }

            return $semaphore->availablePermits();
        });
    }

    /**
     * @throws \Throwable
     */
    public function benchTaskCreateStartComplete(): int
    {
        return $this->runtime->run(static function (CoroutineScope $scope): int {
            return $scope->spawn(static fn(): int => 1)->await();
        });
    }

    /**
     * @throws \Throwable
     */
    public function benchTaskGroupSpawnJoin(): int
    {
        return $this->runtime->run(static function (CoroutineScope $scope): int {
            return $scope->group(static function (CoroutineScope $group): int {
                $tasks = [];
                for ($index = 0; $index < 4; ++$index) {
                    $tasks[] = $group->spawn(static fn(): int => 1);
                }

                return array_sum(array_map(
                    static fn($task): int => $task->await(),
                    $tasks,
                ));
            });
        });
    }

    /**
     * @throws \Throwable
     */
    public function benchUnbufferedChannelHandoff(): int
    {
        return $this->runtime->run(static function (CoroutineScope $scope): int {
            $channel = $scope->channel();
            $producer = $scope->spawn(static function () use ($channel): void {
                for ($value = 1; $value <= 8; ++$value) {
                    $channel->send($value);
                }
            });
            $consumer = $scope->spawn(static function () use ($channel): int {
                $sum = 0;
                for ($index = 0; $index < 8; ++$index) {
                    $sum += self::integerResult($channel->receive());
                }

                return $sum;
            });

            $producer->await();

            return $consumer->await();
        });
    }

    /** @throws \LogicException */
    private static function integerResult(mixed $value): int
    {
        if (!is_int($value)) {
            throw new \LogicException('Coroutine benchmark expected an integer result.');
        }

        return $value;
    }
}
