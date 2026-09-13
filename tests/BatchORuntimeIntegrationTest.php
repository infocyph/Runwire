<?php

declare(strict_types=1);

use Infocyph\Runwire\Coroutine\CoroutineRuntime;
use Infocyph\Runwire\Coroutine\CoroutineScope;
use Infocyph\Runwire\Exception\CancelledException;
use Infocyph\Runwire\Http\Enum\ProtocolVersion;
use Infocyph\Runwire\Http\Headers;
use Infocyph\Runwire\Http\HttpRequest;
use Infocyph\Runwire\Http\Internal\BufferedRequestBody;
use Infocyph\Runwire\Http\Internal\CallbackResponseWriter;
use Infocyph\Runwire\Http\ResponseWriterInterface;
use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\RequestContext;
use Infocyph\Runwire\Runtime\ApplicationLifecycleHooks;
use Infocyph\Runwire\Runtime\CoroutineRequestHandler;
use Infocyph\Runwire\Runtime\Enum\CancellationReason;
use Infocyph\Runwire\Runtime\Host\RuntimeApplication;
use Infocyph\Runwire\Runtime\RequestExecutionPolicy;
use Infocyph\Runwire\Runtime\RequestResetterInterface;

function batchORequest(string $target = '/coroutine'): HttpRequest
{
    return new HttpRequest(
        method: 'GET',
        target: $target,
        version: ProtocolVersion::HTTP_1_1,
        headers: new Headers(),
        body: new BufferedRequestBody(''),
    );
}

function batchOWriter(): ResponseWriterInterface
{
    return new CallbackResponseWriter(
        static function (int $status, Headers $headers): void {},
        static function (string $chunk): void {},
        static function (): void {},
        1_024,
    );
}

it('drains request-owned coroutine work before resetters and request completion', function (): void {
    $events = new ArrayObject();
    $loop = new SelectLoop();
    $runtime = new CoroutineRuntime($loop);
    $request = batchORequest('/ordered');
    $handler = new CoroutineRequestHandler(
        $runtime,
        static function (
            HttpRequest $request,
            ResponseWriterInterface $writer,
            CoroutineScope $scope,
        ) use ($events): void {
            $events[] = 'handler';
            $child = $scope->spawn(static function () use ($events): void {
                $events[] = 'child';
            });
            expect($child->cancellation()->deadline()->monotonicNanoseconds)
                ->toBe($request->context->deadline()->monotonicNanoseconds);
            $events[] = 'handler:return';
            $writer->end();
        },
    );
    $application = new RuntimeApplication(
        $handler,
        runtimeContext: null,
        requestExecution: new RequestExecutionPolicy(maxExecutionSeconds: 5.0),
        lifecycle: new ApplicationLifecycleHooks(resetters: [
            new class($events, $runtime) implements RequestResetterInterface {
                public function __construct(
                    private readonly ArrayObject $events,
                    private readonly CoroutineRuntime $runtime,
                ) {}

                public function reset(RequestContext $context): void
                {
                    expect($this->runtime->activeTaskCount())->toBe(0)
                        ->and($context->completed())->toBeFalse()
                        ->and($context->cancellation->subscriptionCount())->toBe(0);
                    $this->events[] = 'reset';
                }
            },
        ]),
    );

    $application->handle($request, batchOWriter());

    expect(iterator_to_array($events))->toBe([
        'handler',
        'handler:return',
        'child',
        'reset',
    ])->and($runtime->activeTaskCount())->toBe(0)
        ->and($request->context->completed())->toBeTrue()
        ->and($request->context->cancellation->subscriptionCount())->toBe(0);
});

it('propagates request cancellation through suspended coroutine children before reset', function (): void {
    $events = new ArrayObject();
    $loop = new SelectLoop();
    $runtime = new CoroutineRuntime($loop);
    $request = batchORequest('/cancelled');
    $handler = new CoroutineRequestHandler(
        $runtime,
        static function (
            HttpRequest $request,
            ResponseWriterInterface $writer,
            CoroutineScope $scope,
        ) use ($events, $loop): void {
            unset($writer);
            $gate = $scope->deferred();
            $scope->spawn(static function () use ($events, $gate): void {
                try {
                    $gate->future()->await();
                } finally {
                    $events[] = 'child:finally';
                }
            });
            $loop->defer(static function (int $id) use ($events, $request): void {
                unset($id);
                $events[] = 'cancel';
                $request->context->cancel(CancellationReason::HOST_CANCELLED);
            });
            $events[] = 'handler:return';
        },
    );
    $application = new RuntimeApplication(
        $handler,
        lifecycle: new ApplicationLifecycleHooks(resetters: [
            new class($events, $runtime) implements RequestResetterInterface {
                public function __construct(
                    private readonly ArrayObject $events,
                    private readonly CoroutineRuntime $runtime,
                ) {}

                public function reset(RequestContext $context): void
                {
                    expect($this->runtime->activeTaskCount())->toBe(0)
                        ->and($context->completed())->toBeFalse()
                        ->and($context->cancellation->reason())->toBe(CancellationReason::HOST_CANCELLED)
                        ->and($context->cancellation->subscriptionCount())->toBe(0);
                    $this->events[] = 'reset';
                }
            },
        ]),
    );

    expect(static fn() => $application->handle($request, batchOWriter()))
        ->toThrow(CancelledException::class)
        ->and(iterator_to_array($events))->toBe([
            'handler:return',
            'cancel',
            'child:finally',
            'reset',
        ])->and($runtime->activeTaskCount())->toBe(0)
        ->and($request->context->completed())->toBeTrue();
});

it('rejects coroutine execution against an already completed request context', function (): void {
    $runtime = new CoroutineRuntime();
    $context = RequestContext::standalone();
    $context->complete();

    expect(static fn() => $runtime->runRequest(
        $context,
        static function (CoroutineScope $scope): null {
            unset($scope);

            return null;
        },
    ))->toThrow(LogicException::class, 'Completed request context cannot own coroutine work.');
});
