<?php

declare(strict_types=1);

use Infocyph\Runwire\Exception\ApplicationStartupException;
use Infocyph\Runwire\Exception\RuntimeUnavailableException;
use Infocyph\Runwire\Http\HttpRequest;
use Infocyph\Runwire\Http\ResponseWriterInterface;
use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Metrics\Enum\ApplicationErrorClass;
use Infocyph\Runwire\Runtime\ApplicationLifecycleHooks;
use Infocyph\Runwire\Runtime\Enum\ApplicationStartupPhase;
use Infocyph\Runwire\Runtime\Enum\RuntimeCapability;
use Infocyph\Runwire\Runtime\Enum\RuntimeDriver;
use Infocyph\Runwire\Runtime\Host\RuntimeApplication;
use Infocyph\Runwire\Runtime\RequestExecutionPolicy;
use Infocyph\Runwire\Runtime\RuntimeApplicationFactoryInterface;
use Infocyph\Runwire\Runtime\RuntimeApplicationInterface;
use Infocyph\Runwire\RuntimeCapabilities;
use Infocyph\Runwire\RuntimeContext;
use Infocyph\Runwire\Server;
use Infocyph\Runwire\Supervisor\Enum\SupervisorEventType;
use Infocyph\Runwire\Supervisor\Enum\WorkerExitReason;
use Infocyph\Runwire\Supervisor\ReloadPolicy;
use Infocyph\Runwire\Supervisor\RestartPolicy;
use Infocyph\Runwire\Supervisor\Supervisor;
use Infocyph\Runwire\Supervisor\SupervisorEvent;
use Infocyph\Runwire\Supervisor\WorkerContext;
use Infocyph\Runwire\Supervisor\WorkerGroup;

function batchHRuntimeContext(bool $concurrent = false): RuntimeContext
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
        ),
        mode: 'native',
        workerSlot: 0,
        generation: 1,
        pid: 1234,
        concurrent: $concurrent,
    );
}

it('exposes capability-first persistent runtime facts', function (): void {
    $context = batchHRuntimeContext();

    expect($context->supports(RuntimeCapability::PERSISTENT))->toBeTrue()
        ->and($context->supports(RuntimeCapability::CONCURRENT))->toBeFalse()
        ->and($context->supports(RuntimeCapability::OWNS_LISTENER))->toBeTrue()
        ->and($context->supports(RuntimeCapability::OWNS_EVENT_LOOP))->toBeTrue()
        ->and($context->supports(RuntimeCapability::SUPPORTS_HTTP2))->toBeTrue()
        ->and(fn() => $context->requireCapability(RuntimeCapability::SUPPORTS_HTTP3))
        ->toThrow(RuntimeUnavailableException::class);
});

it('creates a native application from the resolved runtime context factory', function (): void {
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    if (!is_array($pair) || count($pair) !== 2) {
        throw new RuntimeException('Unable to create Batch H worker lifecycle pair.');
    }

    [$workerStream, $peerStream] = $pair;
    $worker = new WorkerContext(
        group: 'batch-h',
        slot: 0,
        generation: 1,
        pid: 1234,
        parentPid: 1233,
        readyStream: $workerStream,
    );
    $runtime = batchHRuntimeContext();
    $factory = new class implements RuntimeApplicationFactoryInterface {
        public ?RuntimeContext $createdWith = null;

        public function create(RuntimeContext $context): RuntimeApplicationInterface
        {
            $this->createdWith = $context;

            return new RuntimeApplication(
                static function (HttpRequest $request, ResponseWriterInterface $writer): void {
                    $request->context->setAttribute('batch-h', true);
                    $writer->end();
                },
                runtimeContext: $context,
            );
        }
    };

    try {
        $server = Server::httpApplicationFactory('127.0.0.1:0', $factory, 'factory');
        $application = $server->applicationFor(
            $worker,
            $runtime,
            new RequestExecutionPolicy(),
            new ApplicationLifecycleHooks(),
        );

        expect($factory->createdWith)->toBe($runtime)
            ->and($factory->createdWith?->persistent)->toBeTrue()
            ->and($factory->createdWith?->concurrent)->toBeFalse()
            ->and($application)->toBeInstanceOf(RuntimeApplicationInterface::class);

        $application->start();
        $application->shutdown();
    } finally {
        $worker->close();
        if (is_resource($peerStream)) {
            fclose($peerStream);
        }
    }
});

it('classifies managed warmup failures before request readiness', function (): void {
    $runtime = batchHRuntimeContext();
    $application = new RuntimeApplication(
        static function (HttpRequest $request, ResponseWriterInterface $writer): void {
            $request->context->setAttribute('handled', true);
            $writer->end();
        },
        runtimeContext: $runtime,
        lifecycle: new ApplicationLifecycleHooks(
            warmup: static function (RuntimeContext $context): void {
                if ($context->persistent) {
                    throw new RuntimeException('intentional warmup failure');
                }
            },
        ),
    );
    $failure = null;

    try {
        $application->start();
    } catch (ApplicationStartupException $error) {
        $failure = $error;
    }

    expect($failure)->toBeInstanceOf(ApplicationStartupException::class)
        ->and($failure?->phase)->toBe(ApplicationStartupPhase::WARMUP)
        ->and($runtime->snapshot()->errors[ApplicationErrorClass::WARMUP_FAILURE->value] ?? 0)->toBe(1);
});

it('keeps healthy old generation capacity when replacement warmup exhausts restart budget', function (): void {
    $loop = new SelectLoop();
    $supervisor = new Supervisor(
        $loop,
        new ReloadPolicy(
            maxUnavailable: 0,
            maxSurge: 1,
            replacementReadyTimeoutSeconds: 0.1,
            drainTimeoutSeconds: 0.1,
        ),
    );
    $reloadRequested = false;
    $reloadFailed = false;
    $oldGenerationServing = false;
    $warmupFailures = 0;

    $supervisor->group(WorkerGroup::callbacks(
        name: 'warmup-reload',
        count: 1,
        factory: static function (WorkerContext $context): void {
            if ($context->generation > 1) {
                throw new ApplicationStartupException(
                    ApplicationStartupPhase::WARMUP,
                    new RuntimeException('replacement warmup failed'),
                );
            }

            $context->ready();
            while (!$context->stopping()) {
                usleep(1_000);
            }
        },
        restartPolicy: new RestartPolicy(
            maxRestarts: 1,
            windowSeconds: 1.0,
            initialBackoffSeconds: 0.001,
            maxBackoffSeconds: 0.001,
        ),
        automaticReady: false,
        readyTimeoutSeconds: 0.1,
        shutdownTimeoutSeconds: 0.1,
    ));
    $supervisor->onEvent(
        static function (SupervisorEvent $event) use (
            $supervisor,
            &$reloadRequested,
            &$reloadFailed,
            &$oldGenerationServing,
            &$warmupFailures,
        ): void {
            if (
                !$reloadRequested
                && $event->type === SupervisorEventType::GENERATION_READY
                && $event->generation === 1
            ) {
                $reloadRequested = true;
                $supervisor->reload();

                return;
            }
            if ($event->type !== SupervisorEventType::RELOAD_FAILED) {
                return;
            }

            $reloadFailed = true;
            $status = $supervisor->status();
            $warmupFailures = $status->exitReasonCounts[WorkerExitReason::WARMUP_FAILURE->value] ?? 0;
            foreach ($status->workers as $worker) {
                if ($worker->generation === 1 && $worker->current && $worker->state->serving()) {
                    $oldGenerationServing = true;
                }
            }
            $supervisor->stop();
        },
    );
    $loop->delay(0.5, static function () use ($supervisor): void {
        $supervisor->stop(true);
    });

    $supervisor->run();

    expect($reloadRequested)->toBeTrue()
        ->and($reloadFailed)->toBeTrue()
        ->and($oldGenerationServing)->toBeTrue()
        ->and($warmupFailures)->toBeGreaterThanOrEqual(1);
});
