<?php

declare(strict_types=1);

use Infocyph\Runwire\FpmOptions;
use Infocyph\Runwire\FrankenPhpOptions;
use Infocyph\Runwire\Http\Enum\ProtocolVersion;
use Infocyph\Runwire\Http\Headers;
use Infocyph\Runwire\Http\HttpRequest;
use Infocyph\Runwire\Http\Internal\BufferedRequestBody;
use Infocyph\Runwire\Http\Internal\CallbackResponseWriter;
use Infocyph\Runwire\Http\ResponseWriterInterface;
use Infocyph\Runwire\RequestContext;
use Infocyph\Runwire\RoadRunnerOptions;
use Infocyph\Runwire\Runtime\ApplicationLifecycleHooks;
use Infocyph\Runwire\Runtime\Driver\FpmDriver;
use Infocyph\Runwire\Runtime\Driver\FrankenPhpDriver;
use Infocyph\Runwire\Runtime\Driver\RoadRunnerDriver;
use Infocyph\Runwire\Runtime\Driver\SwooleDriver;
use Infocyph\Runwire\Runtime\Enum\FrankenPhpMode;
use Infocyph\Runwire\Runtime\Enum\RuntimeCapability;
use Infocyph\Runwire\Runtime\Enum\RuntimeDriver;
use Infocyph\Runwire\Runtime\Host\RoadRunnerSessionInterface;
use Infocyph\Runwire\Runtime\Host\RuntimeApplication;
use Infocyph\Runwire\Runtime\RequestExecutionPolicy;
use Infocyph\Runwire\Runtime\RequestResetterInterface;
use Infocyph\Runwire\RuntimeCapabilities;
use Infocyph\Runwire\RuntimeContext;
use Infocyph\Runwire\Supervisor\Enum\ShutdownReason;
use Infocyph\Runwire\Supervisor\WorkerRecyclePolicy;
use Infocyph\Runwire\SwooleOptions;
use Infocyph\Runwire\Tests\Fixtures\FakeSwooleRequest;
use Infocyph\Runwire\Tests\Fixtures\FakeSwooleResponse;

function batchIRuntimeContext(
    RuntimeDriver $driver,
    bool $persistent,
    bool $concurrent = false,
): RuntimeContext {
    return RuntimeContext::fromCapabilities(
        new RuntimeCapabilities(
            driver: $driver,
            persistentProcess: true,
            persistentApplication: $persistent,
            ownsListener: $driver === RuntimeDriver::NATIVE,
            ownsEventLoop: $driver === RuntimeDriver::NATIVE,
            ownsWorkerPool: $driver === RuntimeDriver::NATIVE,
            supportsGracefulReload: $persistent,
            supportsWorkerRecycle: $persistent,
            supportsHttp1: true,
            supportsHttp2: true,
            supportsHttp3: $driver !== RuntimeDriver::SWOOLE && $driver !== RuntimeDriver::FPM,
        ),
        mode: $persistent ? 'worker' : 'request',
        workerSlot: $persistent ? 0 : null,
        generation: $persistent ? 1 : null,
        pid: getmypid() ?: 0,
        concurrent: $concurrent,
    );
}

function batchIRequest(string $target): HttpRequest
{
    return new HttpRequest(
        method: 'GET',
        target: $target,
        version: ProtocolVersion::HTTP_1_1,
        headers: new Headers(),
        body: new BufferedRequestBody(''),
    );
}

function batchIWriter(): ResponseWriterInterface
{
    return new CallbackResponseWriter(
        static function (int $status, Headers $headers): void {},
        static function (string $chunk): void {},
        static function (): void {},
        1_024,
    );
}

/** @return ArrayObject<string, mixed> */
function batchIState(): ArrayObject
{
    return new ArrayObject([
        'boot' => 0,
        'warmup' => 0,
        'handled' => 0,
        'reset' => 0,
        'drain' => 0,
        'shutdown' => 0,
        'deadline_bound' => [],
        'request_contexts' => [],
        'reset_contexts' => [],
        'drain_reason' => null,
        'shutdown_reason' => null,
    ]);
}

function batchIApplication(RuntimeContext $context, ArrayObject $state): RuntimeApplication
{
    $resetter = new class($state) implements RequestResetterInterface {
        public function __construct(private readonly ArrayObject $state) {}

        public function reset(RequestContext $context): void
        {
            $seen = $this->state['reset_contexts'];
            if (!is_array($seen)) {
                throw new RuntimeException('Invalid reset-context fixture state.');
            }
            $seen[] = [$context->hasAttribute('transient'), $context->completed()];
            $this->state['reset_contexts'] = $seen;
            $this->state['reset'] = (int) $this->state['reset'] + 1;
        }
    };

    return new RuntimeApplication(
        static function (HttpRequest $request, ResponseWriterInterface $writer) use ($state): void {
            $state['handled'] = (int) $state['handled'] + 1;
            $request->context->setAttribute('transient', $request->target);

            $contexts = $state['request_contexts'];
            $deadlines = $state['deadline_bound'];
            if (!is_array($contexts) || !is_array($deadlines)) {
                throw new RuntimeException('Invalid lifecycle fixture state.');
            }
            $contexts[] = $request->context;
            $deadlines[] = $request->context->deadline()->monotonicNanoseconds !== null;
            $state['request_contexts'] = $contexts;
            $state['deadline_bound'] = $deadlines;
            $writer->end($request->target);
        },
        runtimeContext: $context,
        requestExecution: new RequestExecutionPolicy(maxExecutionSeconds: 1.0),
        lifecycle: new ApplicationLifecycleHooks(
            boot: static function (RuntimeContext $runtime) use ($state): void {
                if ($runtime->pid < 0) {
                    throw new RuntimeException('Invalid runtime PID.');
                }
                $state['boot'] = (int) $state['boot'] + 1;
            },
            warmup: static function (RuntimeContext $runtime) use ($state): void {
                if ($runtime->mode === '') {
                    throw new RuntimeException('Invalid runtime mode.');
                }
                $state['warmup'] = (int) $state['warmup'] + 1;
            },
            drain: static function (RuntimeContext $runtime, ShutdownReason $reason) use ($state): void {
                $state['drain'] = (int) $state['drain'] + 1;
                $state['drain_reason'] = $runtime->mode . ':' . $reason->value;
            },
            shutdown: static function (RuntimeContext $runtime, ShutdownReason $reason) use ($state): void {
                $state['shutdown'] = (int) $state['shutdown'] + 1;
                $state['shutdown_reason'] = $runtime->mode . ':' . $reason->value;
            },
            resetters: [$resetter],
        ),
    );
}

/** @return array<string, mixed> */
function batchISummary(RuntimeContext $context, RuntimeApplication $application, ArrayObject $state): array
{
    $contexts = $state['request_contexts'];
    if (!is_array($contexts)) {
        throw new RuntimeException('Invalid request-context fixture state.');
    }

    return [
        'persistent' => $context->persistent,
        'concurrent' => $context->concurrent,
        'boot' => $state['boot'],
        'warmup' => $state['warmup'],
        'handled' => $state['handled'],
        'reset' => $state['reset'],
        'drain' => $state['drain'],
        'shutdown' => $state['shutdown'],
        'deadline_bound' => $state['deadline_bound'],
        'contexts_unique' => count(array_unique(array_map(
            static fn(RequestContext $requestContext): int => spl_object_id($requestContext),
            $contexts,
        ))),
        'contexts_completed' => count(array_filter(
            $contexts,
            static fn(RequestContext $requestContext): bool => $requestContext->completed(),
        )),
        'reset_contexts' => $state['reset_contexts'],
        'requests_total' => $application->snapshot()->requestsTotal,
        'requests_active' => $application->snapshot()->requestsActive,
        'drain_reason' => $state['drain_reason'],
        'shutdown_reason' => $state['shutdown_reason'],
    ];
}

/** @return array<string, mixed> */
function batchIRunNative(int $requests): array
{
    $context = batchIRuntimeContext(RuntimeDriver::NATIVE, true);
    $state = batchIState();
    $application = batchIApplication($context, $state);

    for ($index = 0; $index < $requests; ++$index) {
        $application->handle(batchIRequest('/native/' . $index), batchIWriter());
    }
    $application->shutdown(ShutdownReason::SUPERVISOR_STOP);

    return batchISummary($context, $application, $state);
}

/** @return array<string, mixed> */
function batchIRunFpm(): array
{
    $context = batchIRuntimeContext(RuntimeDriver::FPM, false);
    $state = batchIState();
    $application = batchIApplication($context, $state);
    $driver = new FpmDriver(
        new FpmOptions(),
        static fn(): HttpRequest => batchIRequest('/fpm/0'),
        static fn(string $method): ResponseWriterInterface => $method === 'GET'
            ? batchIWriter()
            : throw new RuntimeException('Unexpected FPM request method.'),
    );

    $driver->run($application);

    return batchISummary($context, $application, $state);
}

/** @return array<string, mixed> */
function batchIRunFrankenPhp(int $requests): array
{
    $context = batchIRuntimeContext(RuntimeDriver::FRANKENPHP, true);
    $state = batchIState();
    $application = batchIApplication($context, $state);
    $requestIndex = 0;
    $workerCalls = 0;
    $driver = new FrankenPhpDriver(
        new FrankenPhpOptions(mode: FrankenPhpMode::WORKER),
        static function () use (&$requestIndex): HttpRequest {
            return batchIRequest('/franken/' . $requestIndex++);
        },
        static fn(string $method): ResponseWriterInterface => $method === 'GET'
            ? batchIWriter()
            : throw new RuntimeException('Unexpected FrankenPHP request method.'),
        static function (callable $handler) use (&$workerCalls, $requests): bool {
            ++$workerCalls;
            $handler();

            return $workerCalls < $requests;
        },
    );

    $driver->run($application);

    return batchISummary($context, $application, $state);
}

/** @return array<string, mixed> */
function batchIRunRoadRunner(int $requests): array
{
    $context = batchIRuntimeContext(RuntimeDriver::ROADRUNNER, true);
    $state = batchIState();
    $application = batchIApplication($context, $state);
    $session = new class($requests) implements RoadRunnerSessionInterface {
        private int $index = 0;

        private bool $stopped = false;

        public function __construct(private readonly int $requests) {}

        public function respond(int $status, string $body, array $headers, bool $endOfStream): void {}

        public function stop(): void
        {
            $this->stopped = true;
        }

        public function waitRequest(int $maxRequestBodyBytes): ?HttpRequest
        {
            if ($maxRequestBodyBytes < 1 || $this->stopped || $this->index >= $this->requests) {
                return null;
            }

            return batchIRequest('/rr/' . $this->index++);
        }
    };
    $driver = new RoadRunnerDriver(
        new RoadRunnerOptions(),
        static fn(): RoadRunnerSessionInterface => $session,
    );

    $driver->run($application);

    return batchISummary($context, $application, $state);
}

/** @return array<string, mixed> */
function batchIRunSwoole(int $requests): array
{
    $context = batchIRuntimeContext(RuntimeDriver::SWOOLE, true, true);
    $state = batchIState();
    $application = batchIApplication($context, $state);
    $server = new class($requests) {
        /** @var array<string, bool|int> */
        public array $settings = [];

        /** @var Closure(object, object): void|null */
        private ?Closure $request = null;

        /** @var Closure(): void|null */
        private ?Closure $workerStart = null;

        /** @var Closure(): void|null */
        private ?Closure $workerStop = null;

        public function __construct(private readonly int $requests) {}

        public function on(string $event, callable $handler): bool
        {
            $closure = Closure::fromCallable($handler);

            return match (strtolower($event)) {
                'request' => (bool) ($this->request = $closure),
                'workerstart' => (bool) ($this->workerStart = $closure),
                'workerstop' => (bool) ($this->workerStop = $closure),
                default => false,
            };
        }

        /** @param array<string, bool|int> $settings */
        public function set(array $settings): bool
        {
            $this->settings = $settings;

            return true;
        }

        public function shutdown(): bool
        {
            return true;
        }

        public function start(): bool
        {
            ($this->workerStart)?->__invoke();
            if ($this->request === null) {
                return false;
            }

            for ($index = 0; $index < $this->requests; ++$index) {
                ($this->request)(
                    new FakeSwooleRequest([
                        'request_method' => 'GET',
                        'request_uri' => '/swoole/' . $index,
                        'server_protocol' => 'HTTP/1.1',
                    ], [], ''),
                    new FakeSwooleResponse(),
                );
            }
            ($this->workerStop)?->__invoke();

            return true;
        }

        public function stop(int $workerId = -1, bool $waitEvent = false): bool
        {
            return $workerId === -1 && $waitEvent;
        }
    };
    $driver = new SwooleDriver(
        new SwooleOptions(),
        static fn(string $host, int $port): object => $host !== '' && $port > 0
            ? $server
            : throw new RuntimeException('Invalid Swoole parity endpoint.'),
    );

    $driver->run($application);

    return batchISummary($context, $application, $state);
}

it('keeps lifecycle and request-isolation semantics aligned across persistent runtimes', function (): void {
    $requests = 3;
    $results = [
        batchIRunNative($requests),
        batchIRunFrankenPhp($requests),
        batchIRunRoadRunner($requests),
        batchIRunSwoole($requests),
    ];

    foreach ($results as $result) {
        expect($result['persistent'])->toBeTrue()
            ->and($result['boot'])->toBe(1)
            ->and($result['warmup'])->toBe(1)
            ->and($result['handled'])->toBe($requests)
            ->and($result['reset'])->toBe($requests)
            ->and($result['drain'])->toBe(1)
            ->and($result['shutdown'])->toBe(1)
            ->and($result['deadline_bound'])->toBe(array_fill(0, $requests, true))
            ->and($result['contexts_unique'])->toBe($requests)
            ->and($result['contexts_completed'])->toBe($requests)
            ->and($result['reset_contexts'])->toBe(array_fill(0, $requests, [true, false]))
            ->and($result['requests_total'])->toBe($requests)
            ->and($result['requests_active'])->toBe(0)
            ->and($result['drain_reason'])->toEndWith(':' . ShutdownReason::SUPERVISOR_STOP->value)
            ->and($result['shutdown_reason'])->toEndWith(':' . ShutdownReason::SUPERVISOR_STOP->value);
    }
});

it('keeps FPM request parity explicit without pretending it is a persistent application worker', function (): void {
    $result = batchIRunFpm();

    expect($result['persistent'])->toBeFalse()
        ->and($result['handled'])->toBe(1)
        ->and($result['reset'])->toBe(1)
        ->and($result['boot'])->toBe(1)
        ->and($result['warmup'])->toBe(1)
        ->and($result['drain'])->toBe(1)
        ->and($result['shutdown'])->toBe(1)
        ->and($result['contexts_completed'])->toBe(1)
        ->and($result['requests_total'])->toBe(1);
});

it('reports unsupported and concurrent runtime semantics through capabilities instead of driver switches', function (): void {
    $fpm = batchIRuntimeContext(RuntimeDriver::FPM, false);
    $native = batchIRuntimeContext(RuntimeDriver::NATIVE, true);
    $swoole = batchIRuntimeContext(RuntimeDriver::SWOOLE, true, true);

    expect($fpm->supports(RuntimeCapability::PERSISTENT))->toBeFalse()
        ->and($fpm->supports(RuntimeCapability::SUPPORTS_WORKER_RECYCLE))->toBeFalse()
        ->and($native->supports(RuntimeCapability::PERSISTENT))->toBeTrue()
        ->and($native->supports(RuntimeCapability::OWNS_LISTENER))->toBeTrue()
        ->and($swoole->supports(RuntimeCapability::CONCURRENT))->toBeTrue()
        ->and($swoole->supports(RuntimeCapability::OWNS_LISTENER))->toBeFalse()
        ->and($swoole->supports(RuntimeCapability::SUPPORTS_HTTP3))->toBeFalse();
});
