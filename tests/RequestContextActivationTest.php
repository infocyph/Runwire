<?php

declare(strict_types=1);

use Infocyph\Runwire\Http\Enum\ProtocolVersion;
use Infocyph\Runwire\Http\Headers;
use Infocyph\Runwire\Http\HttpRequest;
use Infocyph\Runwire\Http\Internal\BufferedRequestBody;
use Infocyph\Runwire\Http\Internal\CallbackResponseWriter;
use Infocyph\Runwire\Http\ResponseWriterInterface;
use Infocyph\Runwire\RequestContext;
use Infocyph\Runwire\Runtime\Enum\RuntimeDriver;
use Infocyph\Runwire\Runtime\Host\RuntimeApplication;
use Infocyph\Runwire\Runtime\RequestExecutionPolicy;
use Infocyph\Runwire\RuntimeCapabilities;
use Infocyph\Runwire\RuntimeContext;

function requestContextActivationRuntime(): RuntimeContext
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
        workerSlot: 0,
        generation: 0,
        pid: 1234,
        concurrent: true,
    );
}

function requestContextActivationWriter(): CallbackResponseWriter
{
    return new CallbackResponseWriter(
        static function (): void {},
        static function (): void {},
        static function (): void {},
        1_024,
    );
}

it('rejects a second activation while the same request context is in flight', function (): void {
    $runtime = requestContextActivationRuntime();
    $context = RequestContext::standalone();
    $policy = new RequestExecutionPolicy();

    $context->activate($runtime, $policy);

    expect(fn() => $context->activate($runtime, $policy))
        ->toThrow(LogicException::class, 'Request context is already active.');

    $context->complete();
});

it('rejects re-entrant lifecycle use of the same request object', function (): void {
    $runtime = requestContextActivationRuntime();
    $application = null;
    $application = new RuntimeApplication(
        static function (HttpRequest $request, ResponseWriterInterface $writer) use (&$application): void {
            if (!$application instanceof RuntimeApplication) {
                throw new LogicException('Application lifecycle is unavailable.');
            }

            $application->handle($request, requestContextActivationWriter());
        },
        runtimeContext: $runtime,
    );
    $request = new HttpRequest(
        'GET',
        '/reentrant-context',
        ProtocolVersion::HTTP_1_1,
        new Headers(),
        new BufferedRequestBody(''),
    );

    expect(fn() => $application->handle($request, requestContextActivationWriter()))
        ->toThrow(LogicException::class, 'Request context is already active.')
        ->and($request->context->completed())->toBeTrue();
});
