<?php

declare(strict_types=1);

use Infocyph\Runwire\Exception\RequestLifecycleException;
use Infocyph\Runwire\Exception\ApplicationShutdownException;
use Infocyph\Runwire\Http\Enum\ProtocolVersion;
use Infocyph\Runwire\Http\Headers;
use Infocyph\Runwire\Http\HttpRequest;
use Infocyph\Runwire\Http\Internal\BufferedRequestBody;
use Infocyph\Runwire\Http\Internal\CallbackResponseWriter;
use Infocyph\Runwire\Http\ResponseWriterInterface;
use Infocyph\Runwire\RequestContext;
use Infocyph\Runwire\Runtime\ApplicationLifecycle;
use Infocyph\Runwire\Runtime\ApplicationLifecycleHooks;
use Infocyph\Runwire\Runtime\RequestResetterInterface;
use Infocyph\Runwire\RuntimeContext;
use Infocyph\Runwire\Supervisor\Enum\ShutdownReason;

/**
 * @throws \OverflowException
 */
function lifecycleRequest(string $target = '/lifecycle'): HttpRequest
{
    return new HttpRequest(
        method: 'GET',
        target: $target,
        version: ProtocolVersion::HTTP_1_1,
        headers: new Headers(),
        body: new BufferedRequestBody(''),
    );
}

/**
 * @throws \InvalidArgumentException
 */
function lifecycleWriter(?callable $end = null): ResponseWriterInterface
{
    return new CallbackResponseWriter(
        static function (): void {},
        static function (): void {},
        $end ?? static function (): void {},
        1_024,
    );
}

it('runs boot warmup handle reset drain and shutdown in lifecycle order', function (): void {
    /** @var ArrayObject<int|string, mixed> $events */
    $events = new ArrayObject();
    $hooks = new ApplicationLifecycleHooks(
        boot: static function (RuntimeContext $context) use ($events): void {
            expect($context)->toBeInstanceOf(RuntimeContext::class);
            $events->append('boot');
        },
        warmup: static function (RuntimeContext $context) use ($events): void {
            expect($context)->toBeInstanceOf(RuntimeContext::class);
            $events->append('warmup');
        },
        drain: static function (RuntimeContext $context, ShutdownReason $reason) use ($events): void {
            expect($context)->toBeInstanceOf(RuntimeContext::class)
                ->and($reason)->toBe(ShutdownReason::SUPERVISOR_STOP);
            $events->append('drain');
        },
        shutdown: static function (RuntimeContext $context, ShutdownReason $reason) use ($events): void {
            expect($context)->toBeInstanceOf(RuntimeContext::class)
                ->and($reason)->toBe(ShutdownReason::SUPERVISOR_STOP);
            $events->append('shutdown');
        },
        resetters: [new class($events) implements RequestResetterInterface {
            /** @param ArrayObject<int|string, mixed> $events */
            public function __construct(private readonly ArrayObject $events) {}

            /** @throws \LogicException */
            #[\Override]
            public function reset(RequestContext $context): void
            {
                expect($context->completed())->toBeFalse();
                $marker = $context->attribute('marker');
                if (!is_string($marker)) {
                    throw new LogicException('Request reset did not preserve the marker attribute.');
                }
                $this->events->append('reset:' . $marker);
            }
        }],
    );
    $request = lifecycleRequest();
    $lifecycle = new ApplicationLifecycle(
        static function (HttpRequest $request, ResponseWriterInterface $writer) use ($events): void {
            $events->append('handle');
            $request->context->setAttribute('marker', 'request');
            $writer->end();
        },
        RuntimeContext::standalone(),
        hooks: $hooks,
    );

    $lifecycle->start();
    $lifecycle->start();
    $lifecycle->handle($request, lifecycleWriter());
    $lifecycle->drain();
    $lifecycle->drain();
    $lifecycle->shutdown();
    $lifecycle->shutdown();

    expect(iterator_to_array($events))->toBe([
        'boot',
        'warmup',
        'handle',
        'reset:request',
        'drain',
        'shutdown',
    ])->and($request->context->completed())->toBeTrue()
        ->and($request->context->attributes())->toBe([]);
});

it('runs every resetter and aggregates reset failures after a handler failure', function (): void {
    /** @var ArrayObject<int|string, mixed> $state */
    $state = new ArrayObject();
    $handlerFailure = new RuntimeException('handler failed');
    $hooks = new ApplicationLifecycleHooks(resetters: [
        new class($state) implements RequestResetterInterface {
            /** @param ArrayObject<int|string, mixed> $state */
            public function __construct(private readonly ArrayObject $state) {}

            /**
             * @throws \RuntimeException
             */
            #[\Override]
            public function reset(RequestContext $context): void
            {
                expect($context->completed())->toBeFalse();
                $this->state->append('first');
                throw new RuntimeException('first reset failed');
            }
        },
        new class($state) implements RequestResetterInterface {
            /** @param ArrayObject<int|string, mixed> $state */
            public function __construct(private readonly ArrayObject $state) {}

            #[\Override]
            public function reset(RequestContext $context): void
            {
                expect($context->completed())->toBeFalse();
                $this->state->append('second');
                $context->removeAttribute('dirty');
            }
        },
        new class($state) implements RequestResetterInterface {
            /** @param ArrayObject<int|string, mixed> $state */
            public function __construct(private readonly ArrayObject $state) {}

            /**
             * @throws \RuntimeException
             */
            #[\Override]
            public function reset(RequestContext $context): void
            {
                expect($context->completed())->toBeFalse();
                $this->state->append('third');
                throw new RuntimeException('third reset failed');
            }
        },
    ]);
    $request = lifecycleRequest('/handler-failure');
    $lifecycle = new ApplicationLifecycle(
        static function (HttpRequest $request, ResponseWriterInterface $writer) use ($handlerFailure): void {
            expect($writer->isEnded())->toBeFalse();
            $request->context->setAttribute('dirty', true);
            throw $handlerFailure;
        },
        RuntimeContext::standalone(),
        hooks: $hooks,
    );

    $caught = null;
    try {
        $lifecycle->handle($request, lifecycleWriter());
    } catch (RequestLifecycleException $error) {
        $caught = $error;
    }

    if (!$caught instanceof RequestLifecycleException) {
        throw new LogicException('Expected request lifecycle failure was not thrown.');
    }

    expect($caught->requestFailure)->toBe($handlerFailure)
        ->and($caught->resetFailures)->toHaveCount(2)
        ->and(iterator_to_array($state))->toBe(['first', 'second', 'third'])
        ->and($request->context->completed())->toBeTrue()
        ->and($request->context->attributes())->toBe([]);
});

it('resets request state when automatic response completion fails', function (): void {
    /** @var ArrayObject<int|string, mixed> $state */
    $state = new ArrayObject(['resets' => 0]);
    $request = lifecycleRequest('/response-failure');
    $hooks = new ApplicationLifecycleHooks(resetters: [
        new class($state) implements RequestResetterInterface {
            /** @param ArrayObject<int|string, mixed> $state */
            public function __construct(private readonly ArrayObject $state) {}

            /** @throws \LogicException */
            #[\Override]
            public function reset(RequestContext $context): void
            {
                expect($context->completed())->toBeFalse();
                $resets = $this->state['resets'] ?? null;
                if (!is_int($resets)) {
                    throw new LogicException('Invalid reset-counter fixture state.');
                }
                $this->state['resets'] = $resets + 1;
            }
        },
    ]);
    $lifecycle = new ApplicationLifecycle(
        static function (HttpRequest $request, ResponseWriterInterface $writer): void {
            expect($writer->isEnded())->toBeFalse();
            $request->context->setAttribute('dirty', true);
        },
        RuntimeContext::standalone(),
        hooks: $hooks,
    );
    $writer = lifecycleWriter(static function (): void {
        throw new RuntimeException('response completion failed');
    });

    expect(static fn() => $lifecycle->handle($request, $writer, completeResponse: true))
        ->toThrow(RuntimeException::class, 'response completion failed')
        ->and($state['resets'])->toBe(1)
        ->and($request->context->completed())->toBeTrue()
        ->and($request->context->attributes())->toBe([]);
});

it('lets active work finish after drain and rejects new work', function (): void {
    /** @var ArrayObject<int|string, mixed> $events */
    $events = new ArrayObject();
    $lifecycle = null;
    $hooks = new ApplicationLifecycleHooks(
        drain: static function (RuntimeContext $context, ShutdownReason $reason) use ($events): void {
            expect($context)->toBeInstanceOf(RuntimeContext::class)
                ->and($reason)->toBe(ShutdownReason::SUPERVISOR_STOP);
            $events->append('drain');
        },
        resetters: [new class($events) implements RequestResetterInterface {
            /** @param ArrayObject<int|string, mixed> $events */
            public function __construct(private readonly ArrayObject $events) {}

            #[\Override]
            public function reset(RequestContext $context): void
            {
                expect($context->completed())->toBeFalse();
                $this->events->append('reset');
            }
        }],
    );
    $lifecycle = new ApplicationLifecycle(
        static function (HttpRequest $request, ResponseWriterInterface $writer) use (&$lifecycle, $events): void {
            $events->append('handle-before-drain');
            if (!$lifecycle instanceof ApplicationLifecycle) {
                throw new LogicException('Active lifecycle fixture was not initialized.');
            }
            $lifecycle->drain();
            expect($request->context->cancelled())->toBeFalse();
            $events->append('handle-after-drain');
            $writer->end();
        },
        RuntimeContext::standalone(),
        hooks: $hooks,
    );

    $lifecycle->handle(lifecycleRequest('/active'), lifecycleWriter());

    expect(static fn() => $lifecycle->handle(lifecycleRequest('/new'), lifecycleWriter()))
        ->toThrow(LogicException::class, 'Application lifecycle is draining')
        ->and(iterator_to_array($events))->toBe([
            'handle-before-drain',
            'drain',
            'handle-after-drain',
            'reset',
        ]);
});

it('propagates an explicit worker shutdown reason to drain and shutdown hooks', function (): void {
    /** @var ArrayObject<int|string, mixed> $reasons */
    $reasons = new ArrayObject();
    $lifecycle = new ApplicationLifecycle(
        static function (HttpRequest $request, ResponseWriterInterface $writer): void {
            expect($request->context->completed())->toBeFalse();
            $writer->end();
        },
        RuntimeContext::standalone(),
        hooks: new ApplicationLifecycleHooks(
            drain: static function (RuntimeContext $context, ShutdownReason $reason) use ($reasons): void {
                expect($context)->toBeInstanceOf(RuntimeContext::class);
                $reasons->append('drain:' . $reason->value);
            },
            shutdown: static function (RuntimeContext $context, ShutdownReason $reason) use ($reasons): void {
                expect($context)->toBeInstanceOf(RuntimeContext::class);
                $reasons->append('shutdown:' . $reason->value);
            },
        ),
    );

    $lifecycle->start();
    $lifecycle->drain(ShutdownReason::DEPLOYMENT_RELOAD);
    $lifecycle->shutdown();

    expect(iterator_to_array($reasons))->toBe([
        'drain:deployment_reload',
        'shutdown:deployment_reload',
    ]);
});

it('runs shutdown cleanup after warmup failure without accepting request work', function (): void {
    /** @var ArrayObject<int|string, mixed> $events */
    $events = new ArrayObject();
    $lifecycle = new ApplicationLifecycle(
        static function (HttpRequest $request, ResponseWriterInterface $writer) use ($events): void {
            expect($request->context->completed())->toBeFalse();
            expect($writer->isEnded())->toBeFalse();
            $events->append('handle');
        },
        RuntimeContext::standalone(),
        hooks: new ApplicationLifecycleHooks(
            boot: static function (RuntimeContext $context) use ($events): void {
                expect($context)->toBeInstanceOf(RuntimeContext::class);
                $events->append('boot');
            },
            warmup: static function (RuntimeContext $context) use ($events): void {
                expect($context)->toBeInstanceOf(RuntimeContext::class);
                $events->append('warmup');
                throw new RuntimeException('warmup failed');
            },
            drain: static function (RuntimeContext $context, ShutdownReason $reason) use ($events): void {
                expect($context)->toBeInstanceOf(RuntimeContext::class)
                    ->and($reason)->toBe(ShutdownReason::SUPERVISOR_STOP);
                $events->append('drain');
            },
            shutdown: static function (RuntimeContext $context, ShutdownReason $reason) use ($events): void {
                expect($context)->toBeInstanceOf(RuntimeContext::class)
                    ->and($reason)->toBe(ShutdownReason::SUPERVISOR_STOP);
                $events->append('shutdown');
            },
        ),
    );

    expect(static fn() => $lifecycle->start())->toThrow(RuntimeException::class, 'warmup failed');
    $lifecycle->shutdown();

    expect(iterator_to_array($events))->toBe(['boot', 'warmup', 'drain', 'shutdown']);
});

it('runs and retains every failing shutdown operation', function (): void {
    /** @var ArrayObject<int|string, mixed> $events */
    $events = new ArrayObject();
    $lifecycle = new ApplicationLifecycle(
        static function (): void {},
        RuntimeContext::standalone(),
        hooks: new ApplicationLifecycleHooks(
            drain: static function () use ($events): void {
                $events->append('drain');
                throw new RuntimeException('drain failed');
            },
            shutdown: static function () use ($events): void {
                $events->append('shutdown');
                throw new RuntimeException('shutdown hook failed');
            },
        ),
        legacyShutdown: static function () use ($events): void {
            $events->append('legacy');
            throw new RuntimeException('legacy shutdown failed');
        },
    );
    $lifecycle->start();

    $failure = null;
    try {
        $lifecycle->shutdown();
    } catch (ApplicationShutdownException $error) {
        $failure = $error;
    }

    expect(iterator_to_array($events))->toBe(['drain', 'shutdown', 'legacy'])
        ->and($failure)->toBeInstanceOf(ApplicationShutdownException::class)
        ->and($failure?->shutdownFailures)->toHaveCount(3)
        ->and(array_map(
            static fn(Throwable $error): string => $error->getMessage(),
            $failure?->shutdownFailures ?? [],
        ))->toBe(['drain failed', 'shutdown hook failed', 'legacy shutdown failed']);
});


it('holds admission and cleanup until asynchronous response ownership becomes terminal', function (): void {
    $capture = new class {
        public ?ResponseWriterInterface $writer = null;
    };
    $handled = [];
    /** @var ArrayObject<int|string, mixed> $state */
    $state = new ArrayObject(['resets' => 0]);
    $runtime = RuntimeContext::standalone();
    $lifecycle = new ApplicationLifecycle(
        static function (HttpRequest $request, ResponseWriterInterface $writer) use ($capture, &$handled): void {
            $handled[] = $request->target;
            if ($request->target === '/async') {
                $capture->writer = $writer;

                return;
            }

            $writer->end();
        },
        $runtime,
        hooks: new ApplicationLifecycleHooks(resetters: [
            new class($state) implements RequestResetterInterface {
                /** @param ArrayObject<int|string, mixed> $state */
            public function __construct(private readonly ArrayObject $state) {}

                /** @throws \LogicException */
                #[\Override]
                public function reset(RequestContext $context): void
                {
                    unset($context);
                    $resets = $this->state['resets'] ?? null;
                    if (!is_int($resets)) {
                        throw new LogicException('Invalid reset-counter fixture state.');
                    }
                    $this->state['resets'] = $resets + 1;
                }
            },
        ]),
        admission: new \Infocyph\Runwire\Runtime\AdmissionPolicy(maxActiveRequests: 1),
    );
    $first = lifecycleRequest('/async');

    $lifecycle->handle($first, lifecycleWriter());
    expect($first->context->completed())->toBeFalse()
        ->and($state['resets'])->toBe(0);

    $rejected = [];
    $lifecycle->handle(lifecycleRequest('/rejected'), new CallbackResponseWriter(
        static function (int $status) use (&$rejected): void {
            $rejected[] = $status;
        },
        static function (): void {},
        static function (): void {},
        1_024,
    ));

    expect($handled)->toBe(['/async'])
        ->and($rejected)->toBe([503])
        ->and($capture->writer)->toBeInstanceOf(ResponseWriterInterface::class);

    $pendingWriter = $capture->writer;
    if (!$pendingWriter instanceof ResponseWriterInterface) {
        throw new LogicException('Asynchronous handler did not retain response ownership.');
    }
    $pendingWriter->end();

    expect($first->context->completed())->toBeTrue()
        ->and($state['resets'])->toBe(1);

    $lifecycle->handle(lifecycleRequest('/after'), lifecycleWriter());
    expect($handled)->toBe(['/async', '/after'])
        ->and($state['resets'])->toBe(2);
});

it('latches an unhealthy lifecycle after reset isolation fails and rejects reuse', function (): void {
    $failure = new RuntimeException('isolation reset failed');
    $lifecycle = new ApplicationLifecycle(
        static function (HttpRequest $request, ResponseWriterInterface $writer): void {
            $writer->end($request->target);
        },
        RuntimeContext::standalone(),
        hooks: new ApplicationLifecycleHooks(resetters: [
            new class($failure) implements RequestResetterInterface {
                public function __construct(private readonly RuntimeException $failure) {}

                /**
                 * @throws \RuntimeException
                 */
                #[\Override]
                public function reset(RequestContext $context): void
                {
                    unset($context);

                    throw $this->failure;
                }
            },
        ]),
    );

    expect(fn () => $lifecycle->handle(lifecycleRequest('/dirty'), lifecycleWriter()))
        ->toThrow(RequestLifecycleException::class)
        ->and($lifecycle->healthy())->toBeFalse()
        ->and($lifecycle->healthFailure())->toBe($failure)
        ->and(fn () => $lifecycle->handle(lifecycleRequest('/next'), lifecycleWriter()))
        ->toThrow(LogicException::class, 'unhealthy');
});

it('latches asynchronous reset failure when a delayed response later terminates', function (): void {
    $capture = new class {
        public ?ResponseWriterInterface $writer = null;
    };
    $failure = new RuntimeException('late reset failed');
    $request = lifecycleRequest('/late');
    $lifecycle = new ApplicationLifecycle(
        static function (HttpRequest $request, ResponseWriterInterface $writer) use ($capture): void {
            expect($request->target)->toBe('/late');
            $capture->writer = $writer;
        },
        RuntimeContext::standalone(),
        hooks: new ApplicationLifecycleHooks(resetters: [
            new class($failure) implements RequestResetterInterface {
                public function __construct(private readonly RuntimeException $failure) {}

                /**
                 * @throws \RuntimeException
                 */
                #[\Override]
                public function reset(RequestContext $context): void
                {
                    unset($context);

                    throw $this->failure;
                }
            },
        ]),
    );

    $lifecycle->handle($request, lifecycleWriter());

    expect($request->context->completed())->toBeFalse()
        ->and($lifecycle->healthy())->toBeTrue()
        ->and($capture->writer)->toBeInstanceOf(ResponseWriterInterface::class);
    $pendingWriter = $capture->writer;
    if (!$pendingWriter instanceof ResponseWriterInterface) {
        throw new LogicException('Delayed response was not captured by the handler.');
    }
    expect(fn () => $pendingWriter->end())->toThrow(RequestLifecycleException::class)
        ->and($request->context->completed())->toBeTrue()
        ->and($lifecycle->healthy())->toBeFalse()
        ->and($lifecycle->healthFailure())->toBe($failure);
});
