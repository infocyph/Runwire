<?php

declare(strict_types=1);

use Infocyph\Runwire\Http\Enum\ProtocolVersion;
use Infocyph\Runwire\Http\Headers;
use Infocyph\Runwire\Http\HttpRequest;
use Infocyph\Runwire\Http\Internal\BufferedRequestBody;
use Infocyph\Runwire\Http\Internal\CallbackResponseWriter;
use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Metrics\DefaultRequestIdPolicy;
use Infocyph\Runwire\Metrics\Enum\ApplicationErrorClass;
use Infocyph\Runwire\Metrics\Enum\ProtocolMetric;
use Infocyph\Runwire\Metrics\GcPolicy;
use Infocyph\Runwire\Metrics\RequestIdGeneratorInterface;
use Infocyph\Runwire\Metrics\RuntimeMetrics;
use Infocyph\Runwire\Metrics\RuntimeMetricsSnapshot;
use Infocyph\Runwire\Runtime\Enum\RuntimeDriver;
use Infocyph\Runwire\Runtime\Host\RuntimeApplication;
use Infocyph\Runwire\Runtime\RequestExecutionPolicy;
use Infocyph\Runwire\RuntimeCapabilities;
use Infocyph\Runwire\RuntimeContext;
use Infocyph\Runwire\Supervisor\Enum\SupervisorEventType;
use Infocyph\Runwire\Supervisor\Internal\LifecycleEmitter;
use Infocyph\Runwire\Supervisor\SupervisorEvent;

function batchERuntimeContext(?RuntimeMetrics $metrics = null): RuntimeContext
{
    return RuntimeContext::fromCapabilities(
        new RuntimeCapabilities(
            driver: RuntimeDriver::NATIVE,
            persistentProcess: true,
            persistentApplication: true,
            ownsListener: true,
            ownsEventLoop: true,
            ownsWorkerPool: true,
            supportsHttp1: true,
            supportsHttp2: true,
            supportsHttp3: true,
        ),
        mode: 'native',
        workerSlot: 0,
        generation: 1,
        pid: 1234,
        concurrent: false,
        metrics: $metrics,
    );
}

it('round-trips the versioned bounded metrics snapshot contract', function (): void {
    $metrics = new RuntimeMetrics();
    $metrics->connectionOpened(ProtocolVersion::HTTP_2);
    $metrics->setProtocol(ProtocolMetric::HPACK_TABLE_BYTES, 512);
    $metrics->recordError(ApplicationErrorClass::PROTOCOL_ERROR);
    $snapshot = $metrics->snapshot();
    $array = $snapshot->toArray();

    $decoded = RuntimeMetricsSnapshot::fromArray($array);
    $unknown = $array;
    $unknown['schema_version'] = 99;

    expect($decoded)->not->toBeNull()
        ->and($decoded?->protocol[ProtocolMetric::HPACK_TABLE_BYTES->value])->toBe(512)
        ->and($decoded?->errors[ApplicationErrorClass::PROTOCOL_ERROR->value])->toBe(1)
        ->and(array_keys($decoded?->protocol ?? []))->toHaveCount(count(ProtocolMetric::cases()))
        ->and(array_keys($decoded?->errors ?? []))->toHaveCount(count(ApplicationErrorClass::cases()))
        ->and(RuntimeMetricsSnapshot::fromArray($unknown))->toBeNull();
});

it('applies request-id policy without forcing a tracing header convention', function (): void {
    $runtime = batchERuntimeContext();
    $generator = new class implements RequestIdGeneratorInterface {
        public function generate(RuntimeContext $runtime): string
        {
            return 'generated-' . $runtime->pid;
        }
    };
    $policy = new DefaultRequestIdPolicy($generator);

    expect($policy->resolve($runtime, 'incoming-123'))->toBe('incoming-123')
        ->and($policy->resolve($runtime, "bad\nvalue"))->toBe('generated-1234');
});

it('accounts request failures and adaptive GC policy through the common lifecycle', function (): void {
    $metrics = new RuntimeMetrics();
    $runtime = batchERuntimeContext($metrics);
    $application = new RuntimeApplication(
        static function (): void {
            throw new RuntimeException('handler failed');
        },
        runtimeContext: $runtime,
        requestExecution: new RequestExecutionPolicy(
            gc: new GcPolicy(requestInterval: 2, growthBytes: 0, minimumIntervalSeconds: 0.001),
        ),
    );
    $request = new HttpRequest(
        'GET',
        '/metrics',
        ProtocolVersion::HTTP_2,
        new Headers(),
        new BufferedRequestBody(''),
    );
    $writer = new CallbackResponseWriter(
        static function (): void {},
        static function (): void {},
        static function (): void {},
        1_024,
    );

    expect(fn() => $application->handle($request, $writer))->toThrow(RuntimeException::class);

    $snapshot = $application->snapshot();
    expect($snapshot->requestsTotal)->toBe(1)
        ->and($snapshot->requestsActive)->toBe(0)
        ->and($snapshot->requestsFailedTotal)->toBe(1)
        ->and($snapshot->protocol[ProtocolMetric::HTTP2_STREAMS_TOTAL->value])->toBe(1)
        ->and($snapshot->protocol[ProtocolMetric::HTTP2_STREAMS_ACTIVE->value])->toBe(0)
        ->and($snapshot->errors[ApplicationErrorClass::HANDLER_EXCEPTION->value])->toBe(1);
});

it('exposes bounded event-loop health without retaining callback history', function (): void {
    $loop = new SelectLoop(0.000001);
    $loop->defer(static function (): void {
        usleep(1_000);
    });
    $loop->delay(0.0, static function () use ($loop): void {
        $loop->stop();
    });
    $before = $loop->diagnostics();
    $loop->run();
    $after = $loop->diagnostics();

    expect($before->timersActive)->toBe(1)
        ->and($before->deferredBacklog)->toBe(1)
        ->and($after->callbackOverrunsTotal)->toBeGreaterThanOrEqual(1)
        ->and($after->maxTickNanoseconds)->toBeGreaterThan(0);
});

it('isolates and classifies lifecycle-listener failures for expanded events', function (): void {
    $emitter = new LifecycleEmitter();
    $delivered = 0;
    $emitter->listen(static function (): void {
        throw new RuntimeException('listener failed');
    });
    $emitter->listen(static function (SupervisorEvent $event) use (&$delivered): void {
        if ($event->type === SupervisorEventType::REQUEST_DEADLINE_EXCEEDED) {
            ++$delivered;
        }
    });

    $emitter->emit(new SupervisorEvent(
        SupervisorEventType::REQUEST_DEADLINE_EXCEEDED,
        hrtime(true) / 1_000_000_000,
    ));

    expect($delivered)->toBe(1)
        ->and($emitter->listenerFailures())->toBe(1)
        ->and($emitter->failureCounts()[SupervisorEventType::REQUEST_DEADLINE_EXCEEDED->value])->toBe(1);
});
