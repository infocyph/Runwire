<?php

declare(strict_types=1);

use Infocyph\Runwire\CancellationSource;
use Infocyph\Runwire\CancellationToken;
use Infocyph\Runwire\Http\Enum\ProtocolVersion;
use Infocyph\Runwire\Http\Headers;
use Infocyph\Runwire\Http\HttpRequest;
use Infocyph\Runwire\Http\Internal\BufferedRequestBody;
use Infocyph\Runwire\Http\Internal\CallbackResponseWriter;
use Infocyph\Runwire\Http\Internal\StreamingRequestBody;
use Infocyph\Runwire\Http\ResponseWriterInterface;
use Infocyph\Runwire\RequestContext;
use Infocyph\Runwire\RequestDeadline;
use Infocyph\Runwire\Runtime\Enum\CancellationReason;
use Infocyph\Runwire\Runtime\Enum\RuntimeDriver;
use Infocyph\Runwire\Runtime\Host\RuntimeApplication;
use Infocyph\Runwire\Runtime\RequestExecutionPolicy;
use Infocyph\Runwire\RuntimeCapabilities;
use Infocyph\Runwire\RuntimeContext;

function batchBRuntimeContext(): RuntimeContext
{
    return RuntimeContext::fromCapabilities(
        new RuntimeCapabilities(
            driver: RuntimeDriver::NATIVE,
            persistentProcess: true,
            persistentApplication: true,
            ownsListener: true,
            ownsEventLoop: true,
            ownsWorkerPool: true,
            supportsAsyncIo: true,
            supportsHttp1: true,
            supportsHttp2: true,
        ),
        mode: 'native',
        workerSlot: 2,
        generation: 3,
        pid: 1234,
        concurrent: false,
    );
}

it('exposes immutable runtime facts without driver-name branching', function (): void {
    $context = batchBRuntimeContext();

    expect($context->driver)->toBe(RuntimeDriver::NATIVE)
        ->and($context->mode)->toBe('native')
        ->and($context->workerSlot)->toBe(2)
        ->and($context->generation)->toBe(3)
        ->and($context->pid)->toBe(1234)
        ->and($context->persistent)->toBeTrue()
        ->and($context->concurrent)->toBeFalse()
        ->and($context->ownsListener)->toBeTrue()
        ->and($context->ownsEventLoop)->toBeTrue()
        ->and($context->ownsWorkerPool)->toBeTrue()
        ->and($context->capabilities->supportsHttp2)->toBeTrue();
});

it('isolates and bounds request-scoped attributes then clears them on completion', function (): void {
    $runtime = batchBRuntimeContext();
    $first = RequestContext::create($runtime, maxAttributes: 3);
    $second = RequestContext::create($runtime, maxAttributes: 3);

    $first->setAttribute('trace', 'one');
    $first->setAttribute('auth', ['user' => 42]);
    $first->setAttribute('nullable', null);

    expect($first->attribute('trace'))->toBe('one')
        ->and($first->hasAttribute('nullable'))->toBeTrue()
        ->and($first->attribute('nullable', 'fallback'))->toBeNull()
        ->and($first->attribute('missing', 'fallback'))->toBe('fallback')
        ->and($second->hasAttribute('trace'))->toBeFalse()
        ->and(fn() => $first->setAttribute('overflow', true))->toThrow(OverflowException::class);

    $first->complete();

    expect($first->attributes())->toBe([])
        ->and($first->completed())->toBeTrue()
        ->and(fn() => $first->setAttribute('late', true))->toThrow(LogicException::class)
        ->and(fn() => $first->activate($runtime, new RequestExecutionPolicy()))->toThrow(
            LogicException::class,
            'Completed request context cannot be activated.',
        );
});

it('rejects handling the same request context after completion', function (): void {
    $application = new RuntimeApplication(
        static function (HttpRequest $request, ResponseWriterInterface $writer): void {
            expect($request->context->completed())->toBeFalse();
            $writer->end();
        },
        runtimeContext: batchBRuntimeContext(),
    );
    $request = new HttpRequest(
        'GET',
        '/single-use',
        ProtocolVersion::HTTP_1_1,
        new Headers(),
        new BufferedRequestBody(''),
    );

    $application->handle($request, new CallbackResponseWriter(
        static function (): void {},
        static function (): void {},
        static function (): void {},
        1_024,
    ));

    expect(fn() => $application->handle($request, new CallbackResponseWriter(
        static function (): void {},
        static function (): void {},
        static function (): void {},
        1_024,
    )))->toThrow(LogicException::class, 'Completed request context cannot be activated.');
});

it('cancels once and isolates observer failures', function (): void {
    $source = new CancellationSource(RequestDeadline::unlimited());
    $token = $source->token();
    $called = 0;

    $token->onCancel(static function (CancellationToken $token): void {
        throw new LogicException($token->reason()?->value ?? 'missing');
    });
    $token->onCancel(static function (CancellationToken $token) use (&$called): void {
        if ($token->isCancelled()) {
            ++$called;
        }
    });

    expect($source->cancel(CancellationReason::TRANSPORT_CANCELLED))->toBeTrue()
        ->and($source->cancel(CancellationReason::WORKER_SHUTDOWN))->toBeFalse()
        ->and($token->isCancelled())->toBeTrue()
        ->and($token->reason())->toBe(CancellationReason::TRANSPORT_CANCELLED)
        ->and($called)->toBe(1);
});

it('uses monotonic request deadlines to request cooperative cancellation', function (): void {
    $deadline = RequestDeadline::afterSeconds(0.5, 1_000_000_000);
    $source = new CancellationSource($deadline);
    $token = $source->token();

    expect($token->isCancelled(1_499_999_999))->toBeFalse()
        ->and($token->isCancelled(1_500_000_000))->toBeTrue()
        ->and($token->reason(1_500_000_000))->toBe(CancellationReason::DEADLINE_EXCEEDED)
        ->and($deadline->remainingSeconds(1_250_000_000))->toBe(0.25);
});

it('propagates native body cancellation into the request token', function (): void {
    $body = new StreamingRequestBody(0, 4, 8, static function (): void {});
    $request = new HttpRequest(
        'POST',
        '/cancel',
        ProtocolVersion::HTTP_2,
        new Headers(),
        $body,
    );
    $called = 0;
    $request->context->cancellation->onCancel(static function (CancellationToken $token) use (&$called): void {
        if ($token->reason() === CancellationReason::TRANSPORT_CANCELLED) {
            ++$called;
        }
    });

    $body->cancel();

    expect($request->context->cancelled())->toBeTrue()
        ->and($request->context->cancellation->reason())->toBe(CancellationReason::TRANSPORT_CANCELLED)
        ->and($called)->toBe(1);
});

it('binds host requests to the resolved runtime and clears request state after handling', function (): void {
    $runtime = batchBRuntimeContext();
    $seen = RequestContext::standalone();
    $application = new RuntimeApplication(
        static function (HttpRequest $request, ResponseWriterInterface $writer) use (&$seen): void {
            $seen = $request->context;
            $request->context->setAttribute('trace', 'bound');
            $writer->end('ok');
        },
        runtimeContext: $runtime,
        requestExecution: new RequestExecutionPolicy(maxExecutionSeconds: 2.0),
    );
    $request = new HttpRequest(
        'GET',
        '/context',
        ProtocolVersion::HTTP_1_1,
        new Headers(),
        new BufferedRequestBody(''),
    );
    $writer = new CallbackResponseWriter(
        static function (int $status, Headers $headers): void {},
        static function (string $chunk): void {},
        static function (): void {},
        1_024,
    );

    $application->handle($request, $writer);

    expect($seen->runtime())->toBe($runtime)
        ->and($seen->deadline()->monotonicNanoseconds)->not->toBeNull()
        ->and($seen->attributes())->toBe([])
        ->and($seen->completed())->toBeTrue();
});
