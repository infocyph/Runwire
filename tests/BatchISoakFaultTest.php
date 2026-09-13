<?php

declare(strict_types=1);

use Infocyph\Runwire\Http\Enum\ProtocolVersion;
use Infocyph\Runwire\Http\Headers;
use Infocyph\Runwire\Http\HttpRequest;
use Infocyph\Runwire\Http\Internal\BufferedRequestBody;
use Infocyph\Runwire\Http\Internal\CallbackResponseWriter;
use Infocyph\Runwire\Http\ResponseWriterInterface;
use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Metrics\Enum\ApplicationErrorClass;
use Infocyph\Runwire\Network\ListenerOptions;
use Infocyph\Runwire\Network\SocketCapabilityProbe;
use Infocyph\Runwire\Network\TcpListener;
use Infocyph\Runwire\RequestContext;
use Infocyph\Runwire\Runtime\AdmissionPolicy;
use Infocyph\Runwire\Runtime\ApplicationLifecycle;
use Infocyph\Runwire\Runtime\ApplicationLifecycleHooks;
use Infocyph\Runwire\Runtime\Enum\CancellationReason;
use Infocyph\Runwire\Runtime\Enum\RuntimeDriver;
use Infocyph\Runwire\Runtime\Host\RuntimeApplication;
use Infocyph\Runwire\Runtime\Internal\WorkerRecycleState;
use Infocyph\Runwire\Runtime\RequestExecutionPolicy;
use Infocyph\Runwire\Runtime\RequestResetterInterface;
use Infocyph\Runwire\RuntimeCapabilities;
use Infocyph\Runwire\RuntimeContext;
use Infocyph\Runwire\Supervisor\Enum\ShutdownReason;
use Infocyph\Runwire\Supervisor\Enum\SupervisorEventType;
use Infocyph\Runwire\Supervisor\ReloadPolicy;
use Infocyph\Runwire\Supervisor\Supervisor;
use Infocyph\Runwire\Supervisor\SupervisorEvent;
use Infocyph\Runwire\Supervisor\WorkerContext;
use Infocyph\Runwire\Supervisor\WorkerGroup;
use Infocyph\Runwire\Supervisor\WorkerRecyclePolicy;

function batchISoakRuntime(): RuntimeContext
{
    return RuntimeContext::fromCapabilities(
        new RuntimeCapabilities(
            driver: RuntimeDriver::NATIVE,
            persistentProcess: true,
            persistentApplication: true,
            ownsListener: true,
            ownsEventLoop: true,
            ownsWorkerPool: true,
            supportsGracefulReload: true,
            supportsWorkerRecycle: true,
            supportsHttp1: true,
            supportsHttp2: true,
            supportsHttp3: true,
        ),
        mode: 'native',
        workerSlot: 0,
        generation: 1,
        pid: getmypid() ?: 0,
        concurrent: false,
    );
}

function batchISoakRequest(string $target): HttpRequest
{
    return new HttpRequest(
        method: 'GET',
        target: $target,
        version: ProtocolVersion::HTTP_1_1,
        headers: new Headers(),
        body: new BufferedRequestBody(''),
    );
}

function batchISoakWriter(?ArrayObject $state = null): ResponseWriterInterface
{
    return new CallbackResponseWriter(
        static function (int $status, Headers $headers) use ($state): void {
            if ($state !== null) {
                $state['status'] = $status;
                $state['headers'] = $headers;
            }
        },
        static function (string $chunk) use ($state): void {
            if ($state !== null) {
                $state['body'] = (string) ($state['body'] ?? '') . $chunk;
            }
        },
        static function () use ($state): void {
            if ($state !== null) {
                $state['ended'] = true;
            }
        },
        4_096,
    );
}

/** @return array{memory: int, resources: int} */
function batchISoakSnapshot(): array
{
    gc_collect_cycles();

    return [
        'memory' => memory_get_usage(true),
        'resources' => count(get_resources()),
    ];
}

it('keeps request contexts metrics resetters and cancellation state bounded under sustained churn', function (): void {
    $before = batchISoakSnapshot();
    $runtime = batchISoakRuntime();
    $resets = new ArrayObject(['count' => 0]);
    $resetter = new class($resets) implements RequestResetterInterface {
        public function __construct(private readonly ArrayObject $state) {}

        public function reset(RequestContext $context): void
        {
            if (!$context->hasAttribute('iteration')) {
                throw new RuntimeException('Request-local soak attribute disappeared before reset.');
            }
            $this->state['count'] = (int) $this->state['count'] + 1;
        }
    };
    $iteration = 0;
    $application = new RuntimeApplication(
        static function (HttpRequest $request, ResponseWriterInterface $writer) use (&$iteration): void {
            $request->context->setAttribute('iteration', $iteration);
            if ($iteration % 5 === 0) {
                $request->context->cancel(CancellationReason::DEADLINE_EXCEEDED);
            } elseif ($iteration % 3 === 0) {
                $request->context->cancel(CancellationReason::TRANSPORT_CANCELLED);
            }
            ++$iteration;
            $writer->end('ok');
        },
        runtimeContext: $runtime,
        requestExecution: new RequestExecutionPolicy(maxExecutionSeconds: 1.0),
        lifecycle: new ApplicationLifecycleHooks(resetters: [$resetter]),
    );
    $requestIds = [];

    for ($index = 0; $index < 768; ++$index) {
        $request = batchISoakRequest('/churn/' . $index);
        $requestIds[] = $request->context->requestId;
        $application->handle($request, batchISoakWriter());
        if (!$request->context->completed() || $request->context->attributes() !== []) {
            throw new RuntimeException('Request context leaked state after completion.');
        }
    }
    $application->shutdown();
    $snapshot = $runtime->snapshot();
    unset($application, $request);
    $after = batchISoakSnapshot();

    expect($resets['count'])->toBe(768)
        ->and(count(array_unique($requestIds)))->toBe(768)
        ->and($snapshot->requestsTotal)->toBe(768)
        ->and($snapshot->requestsActive)->toBe(0)
        ->and($snapshot->requestsFailedTotal)->toBeGreaterThan(0)
        ->and($snapshot->errors[ApplicationErrorClass::DEADLINE_EXCEEDED->value] ?? 0)->toBeGreaterThan(0)
        ->and($snapshot->errors[ApplicationErrorClass::CLIENT_CANCELLED->value] ?? 0)->toBeGreaterThan(0)
        ->and($after['resources'] - $before['resources'])->toBeLessThanOrEqual(4)
        ->and($after['memory'] - $before['memory'])->toBeLessThanOrEqual(16 * 1_024 * 1_024);
});

it('uses one deterministic recycle contract for request memory and lifetime triggers', function (): void {
    $requests = new WorkerRecycleState(
        new WorkerRecyclePolicy(maxRequests: 2),
        seed: 1,
        startedAtNs: 1_000_000_000,
    );
    $requests->recordRequestCompleted(nowNs: 1_000_000_000, currentMemoryBytes: 1, peakMemoryBytes: 1);
    $requestRecycle = $requests->recordRequestCompleted(
        nowNs: 1_000_000_001,
        currentMemoryBytes: 1,
        peakMemoryBytes: 1,
    );

    $memory = new WorkerRecycleState(
        new WorkerRecyclePolicy(maxMemoryBytes: 1_024),
        seed: 1,
        startedAtNs: 1_000_000_000,
    );
    $memory->recordRequestCompleted(
        nowNs: 1_000_000_001,
        currentMemoryBytes: 2_048,
        peakMemoryBytes: 4_096,
    );

    $lifetime = new WorkerRecycleState(
        new WorkerRecyclePolicy(maxLifetimeSeconds: 2),
        seed: 1,
        startedAtNs: 1_000_000_000,
    );
    $lifetime->recordRequestCompleted(
        nowNs: 3_000_000_000,
        currentMemoryBytes: 1,
        peakMemoryBytes: 1,
    );

    expect($requestRecycle)->toBeTrue()
        ->and($requests->recycleReason())->toBe(ShutdownReason::RECYCLE_REQUEST_LIMIT)
        ->and($memory->recycleReason())->toBe(ShutdownReason::RECYCLE_MEMORY_LIMIT)
        ->and($lifetime->recycleReason(nowNs: 3_000_000_000))->toBe(ShutdownReason::RECYCLE_LIFETIME);
});

it('recovers admission immediately after bounded overlap rejection', function (): void {
    $runtime = batchISoakRuntime();
    $overload = new ArrayObject();
    $handled = [];
    $lifecycle = null;
    $lifecycle = new ApplicationLifecycle(
        static function (HttpRequest $request, ResponseWriterInterface $writer) use (&$lifecycle, &$handled, $overload): void {
            $handled[] = $request->target;
            if ($request->target === '/primary') {
                if (!$lifecycle instanceof ApplicationLifecycle) {
                    throw new LogicException('Admission fixture is unavailable.');
                }
                $lifecycle->handle(batchISoakRequest('/rejected'), batchISoakWriter($overload));
            }
            $writer->end();
        },
        $runtime,
        admission: new AdmissionPolicy(maxActiveRequests: 1),
    );

    $lifecycle->handle(batchISoakRequest('/primary'), batchISoakWriter());
    $lifecycle->handle(batchISoakRequest('/recovered'), batchISoakWriter());
    $lifecycle->shutdown();

    expect($handled)->toBe(['/primary', '/recovered'])
        ->and($overload['status'] ?? null)->toBe(503)
        ->and($runtime->snapshot()->rejectedRequestsTotal)->toBe(1)
        ->and($runtime->snapshot()->requestsActive)->toBe(0);
});

it('survives repeated rolling reload and drain cycles without leaving child processes', function (): void {
    $loop = new SelectLoop();
    $supervisor = new Supervisor(
        $loop,
        new ReloadPolicy(
            maxUnavailable: 0,
            maxSurge: 1,
            replacementReadyTimeoutSeconds: 0.2,
            drainTimeoutSeconds: 0.1,
        ),
    );
    $readyGenerations = [];
    $reloadCompletions = 0;
    $drains = 0;

    $supervisor->group(WorkerGroup::callbacks(
        name: 'batch-i-reload',
        count: 1,
        factory: static function (WorkerContext $context): void {
            $context->ready();
            while (!$context->stopping()) {
                usleep(1_000);
            }
        },
        automaticReady: false,
        readyTimeoutSeconds: 0.2,
        shutdownTimeoutSeconds: 0.1,
    ));
    $supervisor->onEvent(static function (SupervisorEvent $event) use (
        $supervisor,
        &$readyGenerations,
        &$reloadCompletions,
        &$drains,
    ): void {
        if ($event->type === SupervisorEventType::GENERATION_READY && $event->generation !== null) {
            $readyGenerations[] = $event->generation;
            if ($event->generation === 1) {
                $supervisor->reload();
            }
        }
        if ($event->type === SupervisorEventType::WORKER_DRAIN_STARTED) {
            ++$drains;
        }
        if ($event->type !== SupervisorEventType::RELOAD_COMPLETED) {
            return;
        }

        ++$reloadCompletions;
        if ($reloadCompletions < 2) {
            $supervisor->reload();

            return;
        }
        $supervisor->stop();
    });
    $loop->delay(1.0, static function () use ($supervisor): void {
        $supervisor->stop(true);
    });

    $supervisor->run();

    $status = 0;
    expect($readyGenerations)->toContain(1, 2, 3)
        ->and($reloadCompletions)->toBe(2)
        ->and($drains)->toBeGreaterThanOrEqual(2)
        ->and($supervisor->status()->workers)->toBe([])
        ->and(pcntl_waitpid(-1, $status, WNOHANG))->toBe(-1)
        ->and(pcntl_get_last_error())->toBe(PCNTL_ECHILD);
});

it('tests SO_REUSEPORT as an explicit capability rather than a platform assumption', function (): void {
    if (!SocketCapabilityProbe::supportsReusePort()) {
        expect(static fn() => new ListenerOptions(reusePort: true))
            ->toThrow(InvalidArgumentException::class);

        return;
    }

    $options = new ListenerOptions(reusePort: true);
    $first = TcpListener::bind('127.0.0.1:0', $options);
    $second = null;

    try {
        $second = TcpListener::bind($first->address(), $options);
        expect($second->address())->toBe($first->address());
    } finally {
        $second?->close();
        $first->close();
    }
});
