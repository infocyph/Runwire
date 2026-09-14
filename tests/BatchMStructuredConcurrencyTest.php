<?php

declare(strict_types=1);

use Infocyph\Runwire\Coroutine\CoroutineRuntime;
use Infocyph\Runwire\Coroutine\CoroutineScope;
use Infocyph\Runwire\Coroutine\Enum\TaskGroupFailureMode;
use Infocyph\Runwire\Coroutine\Enum\TaskLocalInheritance;
use Infocyph\Runwire\Coroutine\Exception\TaskGroupException;
use Infocyph\Runwire\Coroutine\TaskLocal;
use Infocyph\Runwire\Exception\CancelledException;
use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\RequestDeadline;
use Infocyph\Runwire\Runtime\Enum\CancellationReason;

it('fails fast on an unhandled child failure and drains cancelled siblings', function (): void {
    $runtime = new CoroutineRuntime();
    $failure = new RuntimeException('primary-failure');
    $siblingCleaned = false;
    $capturedScope = null;
    $caught = null;

    try {
        $runtime->run(function (CoroutineScope $scope) use (
            &$capturedScope,
            &$siblingCleaned,
            $failure,
        ): void {
            $capturedScope = $scope;
            $scope->spawn(function () use ($scope, &$siblingCleaned): void {
                try {
                    $scope->sleep(30.0);
                } finally {
                    $siblingCleaned = true;
                    throw new LogicException('secondary-cleanup-failure');
                }
            });
            $scope->spawn(static function () use ($failure): void {
                throw $failure;
            });
        });
    } catch (Throwable $error) {
        $caught = $error;
    }

    expect($caught)->toBe($failure)
        ->and($siblingCleaned)->toBeTrue()
        ->and($capturedScope)->toBeInstanceOf(CoroutineScope::class)
        ->and($capturedScope->secondaryFailures())->toHaveCount(1)
        ->and($capturedScope->secondaryFailures()[0]->getMessage())->toBe('secondary-cleanup-failure')
        ->and($runtime->activeTaskCount())->toBe(0);
});

it('treats an explicitly awaited and caught child failure as handled', function (): void {
    $runtime = new CoroutineRuntime();

    $result = $runtime->run(function (CoroutineScope $scope): string {
        $failed = $scope->spawn(static function (): void {
            throw new RuntimeException('handled');
        });
        $sibling = $scope->spawn(function () use ($scope): string {
            $scope->yieldNow();

            return 'sibling-completed';
        });

        try {
            $failed->await();
        } catch (RuntimeException $error) {
            expect($error->getMessage())->toBe('handled');
        }

        return $sibling->await();
    });

    expect($result)->toBe('sibling-completed');
});

it('collects all child failures without cancelling the group early', function (): void {
    $runtime = new CoroutineRuntime();

    $failures = $runtime->run(function (CoroutineScope $scope): array {
        try {
            $scope->group(function (CoroutineScope $group): void {
                $group->spawn(static function (): void {
                    throw new RuntimeException('first');
                });
                $group->spawn(static function (): void {
                    throw new LogicException('second');
                });
            }, TaskGroupFailureMode::COLLECT_ALL);
        } catch (TaskGroupException $error) {
            return array_map(
                static fn(Throwable $failure): string => $failure->getMessage(),
                $error->failures(),
            );
        }

        throw new LogicException('Expected collect-all group failure.');
    });

    expect($failures)->toBe(['first', 'second']);
});

it('joins unfinished children when a scope callback returns', function (): void {
    $runtime = new CoroutineRuntime();
    $order = [];

    $result = $runtime->run(function (CoroutineScope $scope) use (&$order): string {
        $scope->spawn(function () use ($scope, &$order): void {
            $scope->yieldNow();
            $order[] = 'child';
        });
        $order[] = 'parent';

        return 'done';
    });

    expect($result)->toBe('done')
        ->and($order)->toBe(['parent', 'child']);
});

it('uses the earliest monotonic deadline across nested scopes', function (): void {
    $runtime = new CoroutineRuntime();
    $clock = hrtime(true);
    $start = is_int($clock) ? $clock : (int) $clock;
    $outer = new RequestDeadline($start + 3_000_000_000);
    $later = new RequestDeadline($start + 5_000_000_000);
    $earlier = new RequestDeadline($start + 1_000_000_000);

    $deadlines = $runtime->run(function (CoroutineScope $scope) use ($earlier, $later, $outer): array {
        return $scope->withDeadline($outer, function (CoroutineScope $outerScope) use ($earlier, $later): array {
            $outerAt = $outerScope->cancellation()->deadline()->monotonicNanoseconds;
            $laterAt = $outerScope->withDeadline(
                $later,
                static fn(CoroutineScope $inner): ?int => $inner->cancellation()->deadline()->monotonicNanoseconds,
            );
            $earlierAt = $outerScope->withDeadline(
                $earlier,
                static fn(CoroutineScope $inner): ?int => $inner->cancellation()->deadline()->monotonicNanoseconds,
            );

            return [$outerAt, $laterAt, $earlierAt];
        });
    });

    expect($deadlines)->toBe([
        $outer->monotonicNanoseconds,
        $outer->monotonicNanoseconds,
        $earlier->monotonicNanoseconds,
    ]);
});

it('drains child cleanup when a nested deadline cancels its owner task', function (): void {
    $loop = new SelectLoop();
    $runtime = new CoroutineRuntime($loop);
    $cleaned = false;
    $caught = null;

    try {
        $runtime->run(function (CoroutineScope $scope) use (&$cleaned): void {
            $clock = hrtime(true);
            $now = is_int($clock) ? $clock : (int) $clock;
            $scope->withDeadline(
                new RequestDeadline($now + 50_000_000),
                function (CoroutineScope $inner) use (&$cleaned): void {
                    $inner->spawn(function () use ($inner, &$cleaned): void {
                        try {
                            $inner->sleep(30.0);
                        } finally {
                            $cleaned = true;
                        }
                    });
                    $inner->sleep(30.0);
                },
            );
        });
    } catch (CancelledException $error) {
        $caught = $error;
    }

    $diagnostics = $loop->diagnostics();
    expect($caught)->toBeInstanceOf(CancelledException::class)
        ->and($caught->reason)->toBe(CancellationReason::DEADLINE_EXCEEDED)
        ->and($cleaned)->toBeTrue()
        ->and($runtime->activeTaskCount())->toBe(0)
        ->and($diagnostics->timersActive)->toBe(0)
        ->and($diagnostics->deferredBacklog)->toBe(0);
});

it('inherits task locals by snapshot while isolating child writes and non-inherited keys', function (): void {
    $runtime = new CoroutineRuntime();
    $shared = new TaskLocal('default');
    $nullable = new TaskLocal('fallback');
    $private = new TaskLocal('private-default', TaskLocalInheritance::NONE);

    $values = $runtime->run(function (CoroutineScope $scope) use ($nullable, $private, $shared): array {
        $scope->setLocal($shared, 'parent');
        $scope->setLocal($nullable, null);
        $scope->setLocal($private, 'parent-private');

        $left = $scope->spawn(function () use ($scope, $nullable, $private, $shared): array {
            $inherited = $scope->local($shared);
            $scope->setLocal($shared, 'left');
            $scope->yieldNow();

            return [$inherited, $scope->local($shared), $scope->local($nullable), $scope->local($private)];
        });
        $right = $scope->spawn(function () use ($scope, $nullable, $private, $shared): array {
            $scope->yieldNow();

            return [$scope->local($shared), $scope->local($nullable), $scope->local($private)];
        });

        return [
            $left->await(),
            $right->await(),
            $scope->local($shared),
            $scope->local($nullable),
            $scope->local($private),
        ];
    });

    expect($values)->toBe([
        ['parent', 'left', null, 'private-default'],
        ['parent', null, 'private-default'],
        'parent',
        null,
        'parent-private',
    ]);
});

it('releases task-local values and request-owned loop state across persistent runtime reuse', function (): void {
    $loop = new SelectLoop();
    $runtime = new CoroutineRuntime($loop);

    for ($iteration = 0; $iteration < 8; ++$iteration) {
        $token = null;
        $weakPayload = null;
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($pair === false) {
            throw new RuntimeException('Unable to create a local stream pair.');
        }

        [$left, $right] = $pair;
        stream_set_blocking($left, false);
        stream_set_blocking($right, false);

        try {
            $result = $runtime->run(function (CoroutineScope $scope) use (
                &$token,
                &$weakPayload,
                $left,
                $right,
            ): string {
                $token = $scope->cancellation();
                $local = new TaskLocal();
                $payload = new stdClass();
                $weakPayload = WeakReference::create($payload);
                $scope->setLocal($local, $payload);
                unset($payload);

                $reader = $scope->spawn(function () use ($scope, $left): string {
                    $scope->waitReadable($left);

                    return (string) fread($left, 1);
                });
                $scope->spawn(function () use ($scope, $right): void {
                    $scope->sleep(0.001);
                    fwrite($right, 'x');
                });

                return $reader->await();
            });
        } finally {
            fclose($left);
            fclose($right);
        }

        gc_collect_cycles();
        $diagnostics = $loop->diagnostics();
        expect($result)->toBe('x')
            ->and($runtime->activeTaskCount())->toBe(0)
            ->and($diagnostics->timersActive)->toBe(0)
            ->and($diagnostics->deferredBacklog)->toBe(0)
            ->and($diagnostics->readWatchers)->toBe(0)
            ->and($diagnostics->writeWatchers)->toBe(0)
            ->and($token)->not->toBeNull()
            ->and($token->subscriptionCount())->toBe(0)
            ->and($weakPayload)->not->toBeNull()
            ->and($weakPayload->get())->toBeNull();
    }
});
