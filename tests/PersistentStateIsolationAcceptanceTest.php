<?php

declare(strict_types=1);

use Infocyph\Runwire\Http\Enum\ProtocolVersion;
use Infocyph\Runwire\Http\Headers;
use Infocyph\Runwire\Http\HttpRequest;
use Infocyph\Runwire\Http\Internal\BufferedRequestBody;
use Infocyph\Runwire\Http\Internal\CallbackResponseWriter;
use Infocyph\Runwire\RequestContext;
use Infocyph\Runwire\Runtime\ApplicationLifecycleHooks;
use Infocyph\Runwire\Runtime\Enum\CancellationReason;
use Infocyph\Runwire\Runtime\Host\RuntimeApplication;
use Infocyph\Runwire\Runtime\RequestExecutionPolicy;
use Infocyph\Runwire\Tests\Support\PersistentFixtureResetter;
use Infocyph\Runwire\Tests\Support\PersistentFixtureState;

function persistentIsolationRequest(string $target, int $startNanoseconds): HttpRequest
{
    return new HttpRequest(
        method: 'GET',
        target: $target,
        version: ProtocolVersion::HTTP_1_1,
        headers: new Headers(),
        body: new BufferedRequestBody(''),
        context: RequestContext::standalone($target, $startNanoseconds),
    );
}

function persistentIsolationWriter(): CallbackResponseWriter
{
    return new CallbackResponseWriter(
        static function (int $status, Headers $headers): void { unset($status, $headers); },
        static function (string $chunk): void { unset($chunk); },
        static function (): void {},
        1024,
    );
}

it('does not leak mutable request state across a long-lived application process', function (): void {
    $state = new PersistentFixtureState();
    $seenClean = 0;
    $requests = 30;
    $policy = new RequestExecutionPolicy(maxExecutionSeconds: 0.001);
    $application = new RuntimeApplication(
        static function (HttpRequest $request, CallbackResponseWriter $writer) use ($state, &$seenClean): void {
            expect($state->clean())->toBeTrue();
            ++$seenClean;

            $request->context->setAttribute('fixture', $request->target);
            $state->dirty($request->target);

            $index = (int) substr($request->target, 1);
            if ($index % 5 === 1) {
                $request->context->cancel(CancellationReason::HOST_CANCELLED);
            }
            if ($index % 5 === 2) {
                throw new RuntimeException('intentional request failure');
            }

            $writer->end('ok');
        },
        lifecycle: new ApplicationLifecycleHooks(
            resetters: [new PersistentFixtureResetter($state)],
        ),
        requestExecution: $policy,
    );

    for ($i = 0; $i < $requests; ++$i) {
        $target = '/' . $i;
        $start = (int) hrtime(true);
        if ($i % 5 === 3) {
            $start -= 10_000_000;
        }
        $request = persistentIsolationRequest($target, $start);

        try {
            $application->handle($request, persistentIsolationWriter());
        } catch (RuntimeException $error) {
            expect($i % 5)->toBe(2)
                ->and($error->getMessage())->toBe('intentional request failure');
        }

        expect($state->clean())->toBeTrue()
            ->and($request->context->completed())->toBeTrue()
            ->and($request->context->attributes())->toBe([]);
    }

    expect($seenClean)->toBe($requests)
        ->and($state->clean())->toBeTrue();
});
