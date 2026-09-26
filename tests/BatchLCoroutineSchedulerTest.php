<?php

declare(strict_types=1);

use Infocyph\Runwire\Coroutine\CoroutinePolicy;
use Infocyph\Runwire\Coroutine\CoroutineRuntime;
use Infocyph\Runwire\Coroutine\CoroutineScope;
use Infocyph\Runwire\Coroutine\Enum\TaskState;
use Infocyph\Runwire\Coroutine\Exception\CoroutineDeadlockException;
use Infocyph\Runwire\Coroutine\Exception\CoroutineOverflowException;
use Infocyph\Runwire\Coroutine\Exception\FutureCompletedException;
use Infocyph\Runwire\Exception\CancelledException;
use Infocyph\Runwire\Http\Enum\ProtocolVersion;
use Infocyph\Runwire\Http\Headers;
use Infocyph\Runwire\Http\HttpRequest;
use Infocyph\Runwire\Http\Internal\BufferedRequestBody;
use Infocyph\Runwire\Http\Internal\CallbackResponseWriter;
use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\RequestContext;
use Infocyph\Runwire\Runtime\AdmissionPolicy;
use Infocyph\Runwire\Runtime\ApplicationLifecycle;
use Infocyph\Runwire\Runtime\ApplicationLifecycleHooks;
use Infocyph\Runwire\Runtime\CoroutineRequestHandler;
use Infocyph\Runwire\Runtime\Enum\CancellationReason;
use Infocyph\Runwire\Runtime\RequestResetterInterface;
use Infocyph\Runwire\RuntimeContext;

it('schedules tasks FIFO and yields without recursive Fiber resume', function (): void {
    $runtime = new CoroutineRuntime();
    $order = [];

    $result = $runtime->run(function (CoroutineScope $scope) use (&$order): array {
        $left = $scope->spawn(function () use ($scope, &$order): int {
            $order[] = 'left-1';
            $scope->yieldNow();
            $order[] = 'left-2';

            return 10;
        });
        $right = $scope->spawn(function () use (&$order): int {
            $order[] = 'right';

            return 20;
        });

        return [$left->await(), $right->await()];
    });

    expect($result)->toBe([10, 20])
        ->and($order)->toBe(['left-1', 'right', 'left-2']);
});

it('supports multiple future waiters and preserves false and null payloads', function (): void {
    $runtime = new CoroutineRuntime();

    $result = $runtime->run(function (CoroutineScope $scope): array {
        $falseDeferred = $scope->deferred();
        $nullDeferred = $scope->deferred();
        $left = $scope->spawn(fn(): mixed => $falseDeferred->future()->await());
        $right = $scope->spawn(fn(): mixed => $falseDeferred->future()->await());
        $null = $scope->spawn(fn(): mixed => $nullDeferred->future()->await());
        $scope->spawn(function () use ($falseDeferred, $nullDeferred): void {
            $falseDeferred->resolve(false);
            $nullDeferred->resolve(null);
        });

        return [$left->await(), $right->await(), $null->await()];
    });

    expect($result)->toBe([false, false, null]);
});

it('rejects a future exactly once and propagates the original exception', function (): void {
    $runtime = new CoroutineRuntime();

    $result = $runtime->run(function (CoroutineScope $scope): string {
        $deferred = $scope->deferred();
        $task = $scope->spawn(fn(): mixed => $deferred->future()->await());
        $scope->spawn(static function () use ($deferred): void {
            $deferred->reject(new RuntimeException('future-failed'));
        });

        try {
            $task->await();
        } catch (RuntimeException $error) {
            expect($error->getMessage())->toBe('future-failed');
        }

        expect(fn() => $deferred->resolve('late'))->toThrow(FutureCompletedException::class);

        return 'handled';
    });

    expect($result)->toBe('handled');
});

it('cancels a suspended task cooperatively and executes Fiber cleanup', function (): void {
    $loop = new SelectLoop();
    $runtime = new CoroutineRuntime($loop);
    $cleaned = false;

    $reason = $runtime->run(function (CoroutineScope $scope) use (&$cleaned): CancellationReason {
        $task = $scope->spawn(function () use ($scope, &$cleaned): void {
            try {
                $scope->sleep(30.0);
            } finally {
                $cleaned = true;
            }
        });
        $scope->yieldNow();
        $task->cancel(CancellationReason::WORKER_SHUTDOWN);

        try {
            $task->await();
        } catch (CancelledException $error) {
            return $error->reason;
        }

        throw new LogicException('Expected the task to be cancelled.');
    });

    expect($reason)->toBe(CancellationReason::WORKER_SHUTDOWN)
        ->and($cleaned)->toBeTrue()
        ->and($loop->diagnostics()->timersActive)->toBe(0);
});

it('waits for real stream readability and unregisters the watcher', function (): void {
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    if ($pair === false) {
        throw new RuntimeException('Unable to create a local stream pair.');
    }

    [$left, $right] = $pair;
    stream_set_blocking($left, false);
    stream_set_blocking($right, false);
    $loop = new SelectLoop();
    $runtime = new CoroutineRuntime($loop);

    try {
        $payload = $runtime->run(function (CoroutineScope $scope) use ($left, $right): string {
            $reader = $scope->spawn(function () use ($scope, $left): string {
                $scope->waitReadable($left);

                return (string) fread($left, 1);
            });
            $scope->spawn(function () use ($scope, $right): void {
                $scope->yieldNow();
                fwrite($right, 'x');
            });

            return $reader->await();
        });

        expect($payload)->toBe('x')
            ->and($loop->diagnostics()->readWatchers)->toBe(0);
    } finally {
        fclose($left);
        fclose($right);
    }
});

it('detects unresolved-future deadlock and cancels suspended tasks for cleanup', function (): void {
    $runtime = new CoroutineRuntime();

    expect(fn() => $runtime->run(function (CoroutineScope $scope): mixed {
        return $scope->deferred()->future()->await();
    }))->toThrow(CoroutineDeadlockException::class);
});

it('enforces the configured live task bound including the root task', function (): void {
    $runtime = new CoroutineRuntime(policy: new CoroutinePolicy(
        maxTasks: 2,
        maxReadyBacklog: 2,
    ));

    expect(fn() => $runtime->run(function (CoroutineScope $scope): void {
        $scope->spawn(function () use ($scope): void {
            $scope->yieldNow();
        });
        $scope->spawn(static function (): void {});
    }))->toThrow(CoroutineOverflowException::class);
});

it('does not start a nested event loop on the same coroutine runtime', function (): void {
    $runtime = new CoroutineRuntime();

    $result = $runtime->run(function (CoroutineScope $scope) use ($runtime): string {
        expect(fn() => $runtime->run(static fn(): null => null))
            ->toThrow(LogicException::class);
        $task = $scope->spawn(static fn(): string => 'still-running');

        return $task->await();
    });

    expect($result)->toBe('still-running');
});

it('exposes terminal task state without retaining it in the scheduler registry', function (): void {
    $runtime = new CoroutineRuntime();
    $task = null;

    $runtime->run(function (CoroutineScope $scope) use (&$task): void {
        $task = $scope->spawn(static fn(): int => 42);
        expect($task->await())->toBe(42);
    });

    expect($task)->not->toBeNull()
        ->and($task->state())->toBe(TaskState::COMPLETED)
        ->and($task->result())->toBe(42);
});


it('runs multiple attached request scopes on one externally owned loop', function (): void {
    $loop = new SelectLoop();
    $runtime = new CoroutineRuntime($loop);
    $events = [];

    $left = $runtime->attachRequest(
        RequestContext::standalone('attached-left'),
        function (CoroutineScope $scope) use (&$events): string {
            $events[] = 'left-start';
            $scope->sleep(0.002);
            $events[] = 'left-end';

            return 'left';
        },
    );
    $right = $runtime->attachRequest(
        RequestContext::standalone('attached-right'),
        function (CoroutineScope $scope) use (&$events): string {
            $events[] = 'right-start';
            $scope->yieldNow();
            $events[] = 'right-end';

            return 'right';
        },
    );
    $loop->defer(static function () use (&$events): void {
        $events[] = 'loop-work';
    });

    expect($runtime->diagnostics()->requestScopesActive)->toBe(2);

    $loop->run();

    expect($left->result())->toBe('left')
        ->and($right->result())->toBe('right')
        ->and($events)->toContain('left-start', 'right-start', 'loop-work', 'right-end', 'left-end')
        ->and($runtime->diagnostics()->requestScopesActive)->toBe(0)
        ->and($runtime->activeTaskCount())->toBe(0);
});

it('propagates request cancellation into an attached scope without nested loop driving', function (): void {
    $loop = new SelectLoop();
    $runtime = new CoroutineRuntime($loop);
    $context = RequestContext::standalone('attached-cancel');
    $cleaned = false;

    $task = $runtime->attachRequest(
        $context,
        function (CoroutineScope $scope) use (&$cleaned): void {
            try {
                $scope->sleep(30.0);
            } finally {
                $cleaned = true;
            }
        },
    );
    $loop->delay(0.002, static function () use ($context): void {
        $context->cancel(CancellationReason::TRANSPORT_CANCELLED);
    });

    $loop->run();

    expect($task->state())->toBe(TaskState::CANCELLED)
        ->and($cleaned)->toBeTrue()
        ->and($runtime->diagnostics()->requestScopesActive)->toBe(0)
        ->and($loop->diagnostics()->timersActive)->toBe(0);
});

it('rejects standalone loop driving while attached request scopes are active', function (): void {
    $loop = new SelectLoop();
    $runtime = new CoroutineRuntime($loop);
    $task = $runtime->attachRequest(
        RequestContext::standalone('attached-ownership'),
        static function (CoroutineScope $scope): void {
            $scope->sleep(0.002);
        },
    );

    expect(fn () => $runtime->run(static fn(): null => null))
        ->toThrow(LogicException::class, 'cannot drive a loop with attached request scopes');

    $loop->run();

    expect($task->state())->toBe(TaskState::COMPLETED);
});

it('attaches coroutine request handlers to an externally owned native loop', function (): void {
    $loop = new SelectLoop();
    $runtime = new CoroutineRuntime();
    $events = [];
    $handler = new CoroutineRequestHandler(
        $runtime,
        static function (HttpRequest $request, $writer, CoroutineScope $scope) use (&$events): void {
            $events[] = 'request-start';
            $scope->sleep(0.002);
            $events[] = 'request-end';
            $writer->end($request->target);
        },
    );
    $handler->attachLoop($loop);
    $request = new HttpRequest(
        method: 'GET',
        target: '/attached-handler',
        version: ProtocolVersion::HTTP_1_1,
        headers: new Headers(),
        body: new BufferedRequestBody(''),
    );
    $body = '';
    $writer = new CallbackResponseWriter(
        static function (): void {},
        static function (string $chunk) use (&$body): void {
            $body .= $chunk;
        },
        static function (): void {},
        1_024,
    );

    $handler($request, $writer);
    $loop->defer(static function () use (&$events): void {
        $events[] = 'loop-work';
    });

    expect($writer->isEnded())->toBeFalse();

    $loop->run();

    expect($writer->isEnded())->toBeTrue()
        ->and($body)->toBe('/attached-handler')
        ->and($events)->toContain('request-start', 'loop-work', 'request-end');
});

it('propagates attached coroutine handler failure through request cancellation', function (): void {
    $loop = new SelectLoop();
    $handler = new CoroutineRequestHandler(
        new CoroutineRuntime(),
        static function (HttpRequest $request, $writer, CoroutineScope $scope): void {
            unset($request, $writer);
            $scope->yieldNow();

            throw new RuntimeException('attached handler failed');
        },
    );
    $handler->attachLoop($loop);
    $request = new HttpRequest(
        method: 'GET',
        target: '/attached-failure',
        version: ProtocolVersion::HTTP_1_1,
        headers: new Headers(),
        body: new BufferedRequestBody(''),
    );
    $writer = new CallbackResponseWriter(
        static function (): void {},
        static function (): void {},
        static function (): void {},
        1_024,
    );

    $handler($request, $writer);
    $loop->run();

    expect($request->context->cancellation->reason())->toBe(CancellationReason::HOST_CANCELLED);
});


it('preserves configured coroutine policy when binding a request handler to the native loop', function (): void {
    $loop = new SelectLoop();
    $handler = new CoroutineRequestHandler(
        new CoroutineRuntime(policy: new CoroutinePolicy(maxTasks: 1, maxReadyBacklog: 1)),
        static function (HttpRequest $request, $writer, CoroutineScope $scope): void {
            unset($request, $writer);
            $scope->spawn(static function (): void {});
        },
    );
    $handler->attachLoop($loop);
    $request = new HttpRequest(
        method: 'GET',
        target: '/attached-policy',
        version: ProtocolVersion::HTTP_1_1,
        headers: new Headers(),
        body: new BufferedRequestBody(''),
    );
    $writer = new CallbackResponseWriter(
        static function (): void {},
        static function (): void {},
        static function (): void {},
        1_024,
    );

    $handler($request, $writer);
    $loop->run();

    expect($request->context->cancellation->reason())->toBe(CancellationReason::HOST_CANCELLED);
});


it('keeps request state and admission until attached coroutine root and children settle', function (): void {
    $loop = new SelectLoop();
    $events = [];
    $resets = 0;
    $handler = new CoroutineRequestHandler(
        new CoroutineRuntime(),
        static function (HttpRequest $request, $writer, CoroutineScope $scope) use (&$events): void {
            $request->context->setAttribute('tenant', 'A');
            $scope->spawn(function () use ($scope, $request, &$events): void {
                $scope->sleep(0.005);
                $events[] = [
                    'child-after-wait',
                    'completed' => $request->context->completed(),
                    'tenant' => $request->context->attribute('tenant'),
                ];
            });
            $writer->end('ok');
            $events[] = [
                'after-end',
                'completed' => $request->context->completed(),
                'tenant' => $request->context->attribute('tenant'),
            ];
        },
    );
    $handler->attachLoop($loop);
    $lifecycle = new ApplicationLifecycle(
        $handler,
        RuntimeContext::standalone(),
        hooks: new ApplicationLifecycleHooks(resetters: [
            new class($resets) implements RequestResetterInterface {
                public function __construct(private int &$resets) {}

                public function reset(RequestContext $context): void
                {
                    expect($context->attribute('tenant'))->toBe('A');
                    ++$this->resets;
                }
            },
        ]),
        admission: new AdmissionPolicy(maxActiveRequests: 1),
    );
    $request = new HttpRequest(
        method: 'GET',
        target: '/attached-lifetime',
        version: ProtocolVersion::HTTP_1_1,
        headers: new Headers(),
        body: new BufferedRequestBody(''),
    );
    $writer = new CallbackResponseWriter(
        static function (): void {},
        static function (): void {},
        static function (): void {},
        1_024,
    );

    $lifecycle->handle($request, $writer);

    expect($request->context->completed())->toBeFalse()
        ->and($resets)->toBe(0);

    $rejected = [];
    $lifecycle->handle(
        new HttpRequest(
            method: 'GET',
            target: '/blocked',
            version: ProtocolVersion::HTTP_1_1,
            headers: new Headers(),
            body: new BufferedRequestBody(''),
        ),
        new CallbackResponseWriter(
            static function (int $status) use (&$rejected): void {
                $rejected[] = $status;
            },
            static function (): void {},
            static function (): void {},
            1_024,
        ),
    );

    expect($rejected)->toBe([503]);

    $loop->delay(0.1, static function () use ($loop): void {
        $loop->stop();
    });
    $loop->run();

    expect($events)->toBe([
        ['after-end', 'completed' => false, 'tenant' => 'A'],
        ['child-after-wait', 'completed' => false, 'tenant' => 'A'],
    ])->and($request->context->completed())->toBeTrue()
        ->and($request->context->attribute('tenant'))->toBeNull()
        ->and($resets)->toBe(1);
});

it('records attached coroutine failure after response output before final cleanup', function (): void {
    $loop = new SelectLoop();
    $failure = new RuntimeException('late attached failure');
    $resets = 0;
    $handler = new CoroutineRequestHandler(
        new CoroutineRuntime(),
        static function (HttpRequest $request, $writer, CoroutineScope $scope) use ($failure): void {
            $request->context->setAttribute('tenant', 'A');
            $writer->end('committed');
            $scope->yieldNow();

            throw $failure;
        },
    );
    $handler->attachLoop($loop);
    $lifecycle = new ApplicationLifecycle(
        $handler,
        RuntimeContext::standalone(),
        hooks: new ApplicationLifecycleHooks(resetters: [
            new class($resets) implements RequestResetterInterface {
                public function __construct(private int &$resets) {}

                public function reset(RequestContext $context): void
                {
                    expect($context->attribute('tenant'))->toBe('A');
                    ++$this->resets;
                }
            },
        ]),
    );
    $request = new HttpRequest(
        method: 'GET',
        target: '/late-failure',
        version: ProtocolVersion::HTTP_1_1,
        headers: new Headers(),
        body: new BufferedRequestBody(''),
    );

    $lifecycle->handle(
        $request,
        new CallbackResponseWriter(
            static function (): void {},
            static function (): void {},
            static function (): void {},
            1_024,
        ),
    );

    $loop->run();

    expect($request->context->completed())->toBeTrue()
        ->and($resets)->toBe(1);
});
