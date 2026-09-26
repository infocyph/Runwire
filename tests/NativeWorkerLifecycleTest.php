<?php

declare(strict_types=1);

use Infocyph\Runwire\Coroutine\CoroutineRuntime;
use Infocyph\Runwire\Coroutine\CoroutineScope;
use Infocyph\Runwire\Http\Enum\ProtocolVersion;
use Infocyph\Runwire\Http\Headers;
use Infocyph\Runwire\Http\HttpRequest;
use Infocyph\Runwire\Http\Internal\BufferedRequestBody;
use Infocyph\Runwire\Http\Internal\CallbackResponseWriter;
use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Metrics\DiagnosticsPolicy;
use Infocyph\Runwire\RequestContext;
use Infocyph\Runwire\Runtime\ApplicationLifecycleHooks;
use Infocyph\Runwire\Runtime\CoroutineRequestHandler;
use Infocyph\Runwire\Runtime\Enum\CancellationReason;
use Infocyph\Runwire\Runtime\Host\RuntimeApplication;
use Infocyph\Runwire\Runtime\Internal\NativeHttpWorker;
use Infocyph\Runwire\Runtime\Internal\NativeHttp3Worker;
use Infocyph\Runwire\Runtime\Internal\WorkerDiagnosticsSampler;
use Infocyph\Runwire\Runtime\RequestResetterInterface;
use Infocyph\Runwire\RuntimeContext;
use Infocyph\Runwire\Supervisor\WorkerContext;
use Infocyph\Runwire\Supervisor\WorkerRecyclePolicy;

function nativeCompletionRequest(): HttpRequest
{
    return new HttpRequest('GET', '/', ProtocolVersion::HTTP_1_1, new Headers(), new BufferedRequestBody(''));
}

function nativeCompletionWriter(): CallbackResponseWriter
{
    return new CallbackResponseWriter(static function (): void {}, static function (): void {}, static function (): void {}, 1024);
}

function nativeCompletionDispatch(string $owner, RuntimeApplication $application, WorkerContext $worker, RuntimeContext $runtime, SelectLoop $loop): Closure
{
    $sampler = new WorkerDiagnosticsSampler($worker, $runtime->metrics, new DiagnosticsPolicy(), $loop);
    $dispatch = (new ReflectionMethod($owner, 'requestHandler'))->invoke(null, $application, $worker, $sampler);
    if (!$dispatch instanceof Closure) {
        throw new RuntimeException('Native worker dispatch factory did not return a closure.');
    }

    return $dispatch;
}

it('counts native completion and recycles only after the coroutine and its children settle', function (string $owner): void {
    $loop = new SelectLoop();
    $runtime = RuntimeContext::standalone();
    $worker = new WorkerContext('test', 0, 1, getmypid(), 0, recyclePolicy: new WorkerRecyclePolicy(maxRequests: 1));
    $events = [];
    $handler = new CoroutineRequestHandler(new CoroutineRuntime(), static function ($request, $writer, CoroutineScope $scope) use ($worker, &$events): void {
        unset($request);
        $scope->spawn(static function () use ($scope, $worker, &$events): void {
            $scope->sleep(0.005);
            $events[] = [$worker->requestsTotal(), $worker->stopping()];
        });
        $writer->end('ok');
        $events[] = [$worker->requestsTotal(), $worker->stopping()];
    });
    $handler->attachLoop($loop);
    $application = new RuntimeApplication($handler, runtimeContext: $runtime);
    $request = nativeCompletionRequest();
    $writer = nativeCompletionWriter();

    try {
        nativeCompletionDispatch($owner, $application, $worker, $runtime, $loop)($request, $writer);
        $loop->delay(0.1, static fn() => $loop->stop());
        $loop->run();
        $request->context->complete();
        $writer->end();

        expect($events)->toBe([[0, false], [0, false]])
            ->and($request->context->completed())->toBeTrue()
            ->and($worker->requestsTotal())->toBe(1)
            ->and($worker->recycling())->toBeTrue();
    } finally {
        $worker->close();
    }
})->with([NativeHttpWorker::class, NativeHttp3Worker::class]);

it('retires native workers when deferred coroutine cleanup fails after response end', function (string $owner): void {
    $loop = new SelectLoop();
    $runtime = RuntimeContext::standalone();
    $worker = new WorkerContext('test', 0, 1, getmypid(), 0);
    $handler = new CoroutineRequestHandler(new CoroutineRuntime(), static function ($request, $writer, CoroutineScope $scope): void {
        unset($request);
        $writer->end('ok');
        $scope->sleep(0.005);
    });
    $handler->attachLoop($loop);
    $application = new RuntimeApplication($handler, runtimeContext: $runtime, lifecycle: new ApplicationLifecycleHooks(resetters: [
        new class implements RequestResetterInterface {
            public function reset(RequestContext $context): void
            {
                expect($context->completed())->toBeFalse();
                throw new RuntimeException('late reset failed');
            }
        },
    ]));
    $request = nativeCompletionRequest();

    try {
        nativeCompletionDispatch($owner, $application, $worker, $runtime, $loop)($request, nativeCompletionWriter());
        $loop->delay(0.1, static fn() => $loop->stop());
        $loop->run();

        expect($application->healthy())->toBeFalse()
            ->and($request->context->completed())->toBeTrue()
            ->and($worker->requestsTotal())->toBe(1)
            ->and($worker->stopping())->toBeTrue();
    } finally {
        $worker->close();
    }
})->with([NativeHttpWorker::class, NativeHttp3Worker::class]);

it('keeps native work active through coroutine cancellation cleanup', function (string $owner): void {
    $loop = new SelectLoop();
    $runtime = RuntimeContext::standalone();
    $worker = new WorkerContext('test', 0, 1, getmypid(), 0, recyclePolicy: new WorkerRecyclePolicy(maxRequests: 1));
    $events = [];
    $handler = new CoroutineRequestHandler(new CoroutineRuntime(), static function ($request, $writer, CoroutineScope $scope) use ($worker, &$events): void {
        unset($writer);
        try {
            $scope->sleep(1.0);
        } finally {
            $events[] = [$request->context->completed(), $worker->requestsTotal(), $worker->stopping()];
        }
    });
    $handler->attachLoop($loop);
    $application = new RuntimeApplication($handler, runtimeContext: $runtime);
    $request = nativeCompletionRequest();

    try {
        nativeCompletionDispatch($owner, $application, $worker, $runtime, $loop)($request, nativeCompletionWriter());
        $loop->delay(0.005, static fn() => $request->context->cancel(CancellationReason::TRANSPORT_CANCELLED));
        $loop->delay(0.1, static fn() => $loop->stop());
        $loop->run();

        expect($events)->toBe([[false, 0, false]])
            ->and($request->context->completed())->toBeTrue()
            ->and($worker->requestsTotal())->toBe(1)
            ->and($worker->recycling())->toBeTrue();
    } finally {
        $worker->close();
    }
})->with([NativeHttpWorker::class, NativeHttp3Worker::class]);

it('accounts rejected and synchronous failed native requests exactly once', function (string $owner): void {
    $loop = new SelectLoop();
    $runtime = RuntimeContext::standalone();
    $worker = new WorkerContext('test', 0, 1, getmypid(), 0);
    $failure = new RuntimeException('synchronous failure');
    $application = new RuntimeApplication(static function () use ($failure): void {
        throw $failure;
    }, runtimeContext: $runtime);

    try {
        $dispatch = nativeCompletionDispatch($owner, $application, $worker, $runtime, $loop);
        expect(fn() => $dispatch(nativeCompletionRequest(), nativeCompletionWriter()))->toThrow($failure)
            ->and($worker->requestsTotal())->toBe(1);

        $application = new RuntimeApplication(static function (): void {}, runtimeContext: $runtime,
            admission: new \Infocyph\Runwire\Runtime\AdmissionPolicy(maxActiveRequests: 1));
        $dispatch = nativeCompletionDispatch($owner, $application, $worker, $runtime, $loop);
        $active = nativeCompletionRequest();
        $activeWriter = nativeCompletionWriter();
        $dispatch($active, $activeWriter);
        $rejected = nativeCompletionRequest();
        $status = null;
        $dispatch($rejected, new CallbackResponseWriter(
            static function (int $code) use (&$status): void { $status = $code; },
            static function (): void {}, static function (): void {}, 1024,
        ));

        expect($status)->toBe(503)
            ->and($rejected->context->completed())->toBeTrue()
            ->and($active->context->completed())->toBeFalse()
            ->and($worker->requestsTotal())->toBe(2);
        $activeWriter->end();
        expect($worker->requestsTotal())->toBe(3);
    } finally {
        $worker->close();
    }
})->with([NativeHttpWorker::class, NativeHttp3Worker::class]);

it('drains an attached native TCP worker after a late coroutine reset failure', function (): void {
    $loop = new SelectLoop();
    $worker = new WorkerContext('test', 0, 1, getmypid(), 0);
    $handler = new CoroutineRequestHandler(new CoroutineRuntime(), static function ($request, $writer, CoroutineScope $scope): void {
        unset($request);
        $writer->end('native-response');
        $scope->sleep(0.005);
    });
    $server = \Infocyph\Runwire\Server::http('127.0.0.1:0', $handler)->withWorkers(1);
    $listener = \Infocyph\Runwire\Network\TcpListener::bind($server->address, $server->listener, $server->connection);
    $handle = NativeHttpWorker::attach(
        $loop, $worker, new \Infocyph\Runwire\Runtime\Internal\BoundServer($server, $listener),
        RuntimeContext::standalone(), new \Infocyph\Runwire\Runtime\RequestExecutionPolicy(),
        new ApplicationLifecycleHooks(resetters: [new class implements RequestResetterInterface {
            public function reset(RequestContext $context): void
            {
                expect($context->completed())->toBeFalse();
                throw new RuntimeException('native late reset failure');
            }
        }]),
    );
    $client = stream_socket_client('tcp://' . $listener->address());
    if (!is_resource($client)) {
        throw new RuntimeException('Unable to connect native lifecycle test client.');
    }

    try {
        fwrite($client, "GET / HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n");
        $loop->delay(0.1, static fn() => $loop->stop());
        $loop->run();
        stream_set_blocking($client, false);

        expect(stream_get_contents($client))->toContain('native-response')
            ->and($worker->stopping())->toBeTrue()
            ->and($worker->requestsTotal())->toBe(1)
            ->and($handle->drained())->toBeTrue();
    } finally {
        fclose($client);
        $handle->close();
        $worker->close();
    }
});
