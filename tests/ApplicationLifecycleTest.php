<?php

declare(strict_types=1);

use Infocyph\Runwire\Exception\RequestLifecycleException;
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

function lifecycleWriter(?callable $end = null): ResponseWriterInterface
{
    return new CallbackResponseWriter(
        static function (int $status, Headers $headers): void {},
        static function (string $chunk): void {},
        $end ?? static function (): void {},
        1_024,
    );
}

it('runs boot warmup handle reset drain and shutdown in lifecycle order', function (): void {
    $events = new ArrayObject();
    $hooks = new ApplicationLifecycleHooks(
        boot: static function (RuntimeContext $context) use ($events): void {
            expect($context)->toBeInstanceOf(RuntimeContext::class);
            $events[] = 'boot';
        },
        warmup: static function (RuntimeContext $context) use ($events): void {
            expect($context)->toBeInstanceOf(RuntimeContext::class);
            $events[] = 'warmup';
        },
        drain: static function (RuntimeContext $context) use ($events): void {
            expect($context)->toBeInstanceOf(RuntimeContext::class);
            $events[] = 'drain';
        },
        shutdown: static function (RuntimeContext $context) use ($events): void {
            expect($context)->toBeInstanceOf(RuntimeContext::class);
            $events[] = 'shutdown';
        },
        resetters: [new class($events) implements RequestResetterInterface {
            public function __construct(private readonly ArrayObject $events) {}

            public function reset(RequestContext $context): void
            {
                expect($context->completed())->toBeFalse();
                $this->events[] = 'reset:' . $context->attribute('marker');
            }
        }],
    );
    $request = lifecycleRequest();
    $lifecycle = new ApplicationLifecycle(
        static function (HttpRequest $request, ResponseWriterInterface $writer) use ($events): void {
            $events[] = 'handle';
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
    $state = new ArrayObject();
    $handlerFailure = new RuntimeException('handler failed');
    $hooks = new ApplicationLifecycleHooks(resetters: [
        new class($state) implements RequestResetterInterface {
            public function __construct(private readonly ArrayObject $state) {}

            public function reset(RequestContext $context): void
            {
                expect($context->completed())->toBeFalse();
                $this->state[] = 'first';
                throw new RuntimeException('first reset failed');
            }
        },
        new class($state) implements RequestResetterInterface {
            public function __construct(private readonly ArrayObject $state) {}

            public function reset(RequestContext $context): void
            {
                expect($context->completed())->toBeFalse();
                $this->state[] = 'second';
                $context->removeAttribute('dirty');
            }
        },
        new class($state) implements RequestResetterInterface {
            public function __construct(private readonly ArrayObject $state) {}

            public function reset(RequestContext $context): void
            {
                expect($context->completed())->toBeFalse();
                $this->state[] = 'third';
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
    $state = new ArrayObject(['resets' => 0]);
    $request = lifecycleRequest('/response-failure');
    $hooks = new ApplicationLifecycleHooks(resetters: [
        new class($state) implements RequestResetterInterface {
            public function __construct(private readonly ArrayObject $state) {}

            public function reset(RequestContext $context): void
            {
                expect($context->completed())->toBeFalse();
                $this->state['resets'] = $this->state['resets'] + 1;
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
    $events = new ArrayObject();
    $lifecycle = null;
    $hooks = new ApplicationLifecycleHooks(
        drain: static function (RuntimeContext $context) use ($events): void {
            expect($context)->toBeInstanceOf(RuntimeContext::class);
            $events[] = 'drain';
        },
        resetters: [new class($events) implements RequestResetterInterface {
            public function __construct(private readonly ArrayObject $events) {}

            public function reset(RequestContext $context): void
            {
                expect($context->completed())->toBeFalse();
                $this->events[] = 'reset';
            }
        }],
    );
    $lifecycle = new ApplicationLifecycle(
        static function (HttpRequest $request, ResponseWriterInterface $writer) use (&$lifecycle, $events): void {
            $events[] = 'handle-before-drain';
            $lifecycle->drain();
            expect($request->context->cancelled())->toBeFalse();
            $events[] = 'handle-after-drain';
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

it('runs shutdown cleanup after warmup failure without accepting request work', function (): void {
    $events = new ArrayObject();
    $lifecycle = new ApplicationLifecycle(
        static function (HttpRequest $request, ResponseWriterInterface $writer) use ($events): void {
            expect($request->context->completed())->toBeFalse();
            expect($writer->isEnded())->toBeFalse();
            $events[] = 'handle';
        },
        RuntimeContext::standalone(),
        hooks: new ApplicationLifecycleHooks(
            boot: static function (RuntimeContext $context) use ($events): void {
                expect($context)->toBeInstanceOf(RuntimeContext::class);
                $events[] = 'boot';
            },
            warmup: static function (RuntimeContext $context) use ($events): void {
                expect($context)->toBeInstanceOf(RuntimeContext::class);
                $events[] = 'warmup';
                throw new RuntimeException('warmup failed');
            },
            drain: static function (RuntimeContext $context) use ($events): void {
                expect($context)->toBeInstanceOf(RuntimeContext::class);
                $events[] = 'drain';
            },
            shutdown: static function (RuntimeContext $context) use ($events): void {
                expect($context)->toBeInstanceOf(RuntimeContext::class);
                $events[] = 'shutdown';
            },
        ),
    );

    expect(static fn() => $lifecycle->start())->toThrow(RuntimeException::class, 'warmup failed');
    $lifecycle->shutdown();

    expect(iterator_to_array($events))->toBe(['boot', 'warmup', 'drain', 'shutdown']);
});
