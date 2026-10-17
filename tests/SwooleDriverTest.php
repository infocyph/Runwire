<?php

declare(strict_types=1);

use Infocyph\Runwire\Http\Enum\ProtocolVersion;
use Infocyph\Runwire\Http\Headers;
use Infocyph\Runwire\Http\HttpRequest;
use Infocyph\Runwire\Exception\RequestLifecycleException;
use Infocyph\Runwire\Http\ResponseWriterInterface;
use Infocyph\Runwire\Runtime\Driver\SwooleDriver;
use Infocyph\Runwire\Runtime\Enum\RuntimeDriver;
use Infocyph\Runwire\Runtime\Host\RuntimeApplication;
use Infocyph\Runwire\Runtime\RuntimeEnvironment;
use Infocyph\Runwire\Runtime\RuntimeSelector;
use Infocyph\Runwire\RuntimeOptions;
use Infocyph\Runwire\RuntimeContext;
use Infocyph\Runwire\Supervisor\WorkerRecyclePolicy;
use Infocyph\Runwire\SwooleOptions;
use Infocyph\Runwire\Tests\Fixtures\FakeSwooleRequest;
use Infocyph\Runwire\Tests\Fixtures\FakeSwooleResponse;
use Infocyph\Runwire\Tests\Fixtures\FakeSwooleServer;

it('creates a Swoole application only inside worker startup with a fresh worker context', function (): void {
    $server = new FakeSwooleServer(new FakeSwooleRequest([
        'request_method' => 'GET', 'request_uri' => '/worker', 'server_protocol' => 'HTTP/1.1',
    ], [], ''), new FakeSwooleResponse());
    $driver = new SwooleDriver(new SwooleOptions(workerCount: 2), static fn(): object => $server);
    $selection = new RuntimeSelector()->select(new RuntimeOptions(driver: RuntimeDriver::SWOOLE), new RuntimeEnvironment(
        sapi: 'cli', availableDrivers: [RuntimeDriver::SWOOLE],
    ));
    $parent = RuntimeContext::fromCapabilities($selection->capabilities, 'host', pid: getmypid() + 100_000);
    $seen = [];
    $driver->runFactory(static function (RuntimeContext $worker) use (&$seen, $parent): RuntimeApplication {
        expect($worker)->not->toBe($parent)
            ->and($worker->pid)->toBe(getmypid())
            ->and($worker->workerSlot)->toBe(0)
            ->and($worker->capabilities)->toBe($parent->capabilities);
        $seen[] = 'create';

        return new RuntimeApplication(
            static function (HttpRequest $request, ResponseWriterInterface $writer) use ($worker, &$seen): void {
                expect($request->context->runtime())->toBe($worker);
                $seen[] = 'request';
                $writer->end('worker-ok');
            },
            shutdown: static function () use (&$seen): void { $seen[] = 'shutdown'; },
            runtimeContext: $worker,
            lifecycle: new \Infocyph\Runwire\Runtime\ApplicationLifecycleHooks(
                boot: static function () use (&$seen): void { $seen[] = 'boot'; },
                drain: static function () use (&$seen): void { $seen[] = 'drain'; },
            ),
        );
    }, $parent);

    expect($seen)->toBe(['create', 'boot', 'request', 'drain', 'shutdown'])
        ->and($server->response->chunks)->toBe(['worker-ok']);
});

it('rejects a foreign runtime context before constructing a Swoole host', function (): void {
    $driver = new SwooleDriver(new SwooleOptions(), static function (): never {
        throw new LogicException('Invalid context started the host.');
    });
    expect(fn() => $driver->runFactory(static function (): never {
        throw new LogicException('Invalid context constructed the application.');
    }, RuntimeContext::standalone()))->toThrow(InvalidArgumentException::class, 'require a Swoole runtime context');
});

it('does not construct an application when Swoole configuration fails', function (): void {
    $server = new class {
        public function set(array $settings): bool { unset($settings); return false; }
    };
    $created = 0;
    $driver = new SwooleDriver(new SwooleOptions(), static fn(): object => $server);
    expect(fn() => $driver->runFactory(static function () use (&$created): never {
        $created++;
        throw new LogicException('Factory must not run in the supervisor.');
    }, swooleWorkerTemplate()))->toThrow(RuntimeException::class, 'rejected the configured server settings')
        ->and($created)->toBe(0);
});

it('preserves startup and shutdown failures and releases a failed worker exactly once', function (): void {
    $server = new FakeSwooleServer(new FakeSwooleRequest([], [], ''), new FakeSwooleResponse());
    $driver = new SwooleDriver(new SwooleOptions(), static fn(): object => $server);
    $bootFailure = new RuntimeException('worker boot failed');
    $shutdownFailure = new RuntimeException('worker shutdown failed');
    $shutdowns = 0;
    $caught = null;
    try {
        $driver->runFactory(static function (RuntimeContext $context) use ($bootFailure, $shutdownFailure, &$shutdowns): RuntimeApplication {
            return new RuntimeApplication(
                static function (): void { throw new LogicException('An unbooted worker handled a request.'); },
                runtimeContext: $context,
                lifecycle: new \Infocyph\Runwire\Runtime\ApplicationLifecycleHooks(
                    boot: static function () use ($bootFailure): never { throw $bootFailure; },
                    shutdown: static function () use (&$shutdowns, $shutdownFailure): never { $shutdowns++; throw $shutdownFailure; },
                ),
            );
        }, swooleWorkerTemplate());
    } catch (\Infocyph\Runwire\Exception\ApplicationShutdownException $error) {
        $caught = $error;
    }
    expect($caught)->toBeInstanceOf(\Infocyph\Runwire\Exception\ApplicationShutdownException::class)
        ->and($caught?->primaryFailure?->getPrevious())->toBe($bootFailure)
        ->and($caught?->shutdownFailures)->toBe([$shutdownFailure])
        ->and($shutdowns)->toBe(1)
        ->and($server->response->ends)->toBe(0);
});

it('creates independent contexts and metrics when Swoole starts or replaces a worker', function (): void {
    $server = new FakeSwooleServer(new FakeSwooleRequest([], [], ''), new FakeSwooleResponse());
    $server->workerIds = [0, 1, 0];
    $driver = new SwooleDriver(new SwooleOptions(), static fn(): object => $server);
    $contexts = [];
    $shutdowns = [];
    $driver->runFactory(static function (RuntimeContext $context) use (&$contexts, &$shutdowns): RuntimeApplication {
        $contexts[] = $context;

        return new RuntimeApplication(
            static function (HttpRequest $request, ResponseWriterInterface $writer) use ($context): void {
                expect($request->context->runtime())->toBe($context);
                $writer->end('worker');
            },
            shutdown: static function () use (&$shutdowns, $context): void { $shutdowns[] = $context; },
            runtimeContext: $context,
        );
    }, swooleWorkerTemplate());

    expect($contexts)->toHaveCount(3)
        ->and($shutdowns)->toBe($contexts)
        ->and(array_column($contexts, 'workerSlot'))->toBe([0, 1, 0])
        ->and($contexts[0])->not->toBe($contexts[2])
        ->and($contexts[0]->metrics)->not->toBe($contexts[1]->metrics);
    foreach ($contexts as $context) {
        expect($context->snapshot()->requestsTotal)->toBe(1)
            ->and($context->snapshot()->requestsActive)->toBe(0);
    }
});

function swooleWorkerTemplate(): RuntimeContext
{
    $selection = new RuntimeSelector()->select(new RuntimeOptions(driver: RuntimeDriver::SWOOLE), new RuntimeEnvironment(
        sapi: 'cli', availableDrivers: [RuntimeDriver::SWOOLE],
    ));

    return RuntimeContext::fromCapabilities($selection->capabilities, 'worker');
}

it('validates and maps bounded Swoole server settings with generic recycle policy', function (): void {
    $options = new SwooleOptions(
        host: '::1',
        port: 9601,
        workerCount: 4,
        maxRequestBodyBytes: 32_768,
        maxResponseBytes: 65_536,
        http2: true,
    );

    expect($options->serverSettings(new WorkerRecyclePolicy(maxRequests: 500, jitterRequests: 50)))->toBe([
        'enable_coroutine' => true,
        'package_max_length' => 65_536,
        'worker_num' => 4,
        'max_request' => 500,
        'max_request_grace' => 50,
        'open_http2_protocol' => true,
    ]);
});

it('runs Swoole through the common request and response contract', function (): void {
    $request = new FakeSwooleRequest(
        [
            'request_method' => 'POST',
            'request_uri' => '/swoole',
            'query_string' => 'page=2',
            'server_protocol' => 'HTTP/2',
            'remote_addr' => '2001:db8::8',
            'remote_port' => 43100,
            'server_addr' => '127.0.0.1',
            'server_port' => 9501,
            'request_scheme' => 'https',
        ],
        [
            'content-type' => 'application/json',
            'x-trace-id' => 'trace-42',
        ],
        '{"ok":true}',
    );
    $response = new FakeSwooleResponse();
    $server = new FakeSwooleServer($request, $response);
    $factoryHost = null;
    $factoryPort = null;
    $cleaned = 0;
    $shutdown = 0;
    $seen = [];
    $application = new RuntimeApplication(
        static function (HttpRequest $request, ResponseWriterInterface $writer) use (&$seen): void {
            $seen = [
                'method' => $request->method,
                'target' => $request->target,
                'version' => $request->version,
                'trace' => $request->headers->first('x-trace-id'),
                'peer' => $request->peerAddress,
                'local' => $request->localAddress,
                'encrypted' => $request->encrypted,
                'body' => $request->body->read(),
            ];
            $writer->start(201, Headers::fromArray([
                'content-type' => 'text/plain',
                'set-cookie' => ['a=1', 'b=2'],
            ]));
            $writer->end('accepted');
        },
        static function () use (&$cleaned): void {
            ++$cleaned;
        },
        static function () use (&$shutdown): void {
            ++$shutdown;
        },
    );
    $driver = new SwooleDriver(
        new SwooleOptions(host: '::1', port: 9502, workerCount: 2, http2: true),
        static function (string $host, int $port) use (&$factoryHost, &$factoryPort, $server): object {
            $factoryHost = $host;
            $factoryPort = $port;

            return $server;
        },
        new WorkerRecyclePolicy(maxRequests: 100),
    );

    $driver->run($application);

    expect($factoryHost)->toBe('::1')
        ->and($factoryPort)->toBe(9502)
        ->and($cleaned)->toBe(1)
        ->and($shutdown)->toBe(1)
        ->and($seen)->toBe([
            'method' => 'POST',
            'target' => '/swoole?page=2',
            'version' => ProtocolVersion::HTTP_2,
            'trace' => 'trace-42',
            'peer' => '[2001:db8::8]:43100',
            'local' => '127.0.0.1:9501',
            'encrypted' => true,
            'body' => '{"ok":true}',
        ])
        ->and($response->statuses)->toBe([201])
        ->and($response->headers)->toBe([
            ['content-type', 'text/plain'],
            ['set-cookie', 'a=1'],
            ['set-cookie', 'b=2'],
        ])
        ->and($response->chunks)->toBe(['accepted'])
        ->and($response->ends)->toBe(1)
        ->and($server->settings['enable_coroutine'])->toBeTrue()
        ->and($server->settings['worker_num'])->toBe(2)
        ->and($server->settings['max_request'])->toBe(100)
        ->and($server->settings['max_request_grace'])->toBe(0)
        ->and($server->settings['open_http2_protocol'])->toBeTrue();
});

it('uses the safe current-worker stop path for generic memory or lifetime recycling', function (): void {
    $request = new FakeSwooleRequest([
        'request_method' => 'GET',
        'request_uri' => '/recycle',
        'server_protocol' => 'HTTP/1.1',
    ], [], '');
    $response = new FakeSwooleResponse();
    $server = new class($request, $response) {
        /** @var array<string, bool|int> */
        public array $settings = [];

        /** @var Closure(object, object): void|null */
        private ?Closure $requestHandler = null;

        /** @var Closure(): void|null */
        private ?Closure $workerStart = null;

        public int $workerStops = 0;

        public function __construct(
            private readonly FakeSwooleRequest $request,
            private readonly FakeSwooleResponse $response,
        ) {}

        public function on(string $event, callable $handler): bool
        {
            if (strtolower($event) === 'workerstart') {
                $this->workerStart = Closure::fromCallable($handler);

                return true;
            }
            if (strtolower($event) === 'request') {
                $this->requestHandler = Closure::fromCallable($handler);

                return true;
            }

            return false;
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
            if ($this->requestHandler === null) {
                return false;
            }
            ($this->requestHandler)($this->request, $this->response);

            return true;
        }

        public function stop(int $workerId = -1, bool $waitEvent = false): bool
        {
            if ($workerId !== -1 || !$waitEvent) {
                return false;
            }
            ++$this->workerStops;

            return true;
        }
    };
    $driver = new SwooleDriver(
        new SwooleOptions(),
        static fn(string $host, int $port): object => $host !== '' && $port > 0
            ? $server
            : throw new RuntimeException('Invalid Swoole test endpoint.'),
        new WorkerRecyclePolicy(maxMemoryBytes: 1),
    );

    $driver->run(new RuntimeApplication(static function (HttpRequest $request, ResponseWriterInterface $writer): void {
        $writer->end($request->target);
    }));

    expect($server->workerStops)->toBe(1);
});

it('delegates Swoole stop to the active host server', function (): void {
    $server = new FakeSwooleServer(
        new FakeSwooleRequest([
            'request_method' => 'GET',
            'request_uri' => '/',
            'server_protocol' => 'HTTP/1.1',
        ], [], ''),
        new FakeSwooleResponse(),
    );
    $driver = new SwooleDriver(
        new SwooleOptions(),
        static fn(string $host, int $port): object => $host !== '' && $port > 0
            ? $server
            : throw new RuntimeException('Invalid Swoole test endpoint.'),
    );
    $server->startHook = static function () use ($driver): void {
        $driver->stop();
    };

    $driver->run(new RuntimeApplication(static function (HttpRequest $request, ResponseWriterInterface $writer): void {
        $writer->end($request->target);
    }));

    expect($server->shutdownCalled)->toBeTrue();
});

it('reports host-owned Swoole persistence with the Runwire reactor bridge available', function (): void {
    $environment = new RuntimeEnvironment(
        sapi: 'cli',
        availableDrivers: [RuntimeDriver::SWOOLE],
        opcacheAvailable: true,
        opcacheCliEnabled: true,
    );
    $selection = new RuntimeSelector()->select(
        new RuntimeOptions(
            driver: RuntimeDriver::SWOOLE,
            swoole: new SwooleOptions(http2: true),
        ),
        $environment,
    );

    expect($selection->capabilities->persistentApplication)->toBeTrue()
        ->and($selection->capabilities->supportsAsyncIo)->toBeTrue()
        ->and($selection->capabilities->hostOwnsEventLoop)->toBeTrue()
        ->and($selection->capabilities->runwireLoopAvailable)->toBeTrue()
        ->and($selection->capabilities->hostNativeCoroutines)->toBeTrue()
        ->and($selection->capabilities->supportsRunwireCoroutines)->toBeTrue()
        ->and($selection->capabilities->supportsWorkerRecycle)->toBeTrue()
        ->and($selection->capabilities->supportsHttp1)->toBeTrue()
        ->and($selection->capabilities->supportsHttp2)->toBeTrue()
        ->and($selection->capabilities->supportsHttp3)->toBeFalse()
        ->and($selection->capabilities->ownsHttp1Wire)->toBeFalse()
        ->and($selection->capabilities->ownsHttp2Wire)->toBeFalse();
});


it('auto-completes Swoole responses and retires an unhealthy worker after reset failure', function (): void {
    $request = new FakeSwooleRequest([
        'request_method' => 'GET',
        'request_uri' => '/unhealthy',
        'server_protocol' => 'HTTP/1.1',
    ], [], '');
    $response = new FakeSwooleResponse();
    $server = new class($request, $response) {
        /** @var array<string, bool|int> */
        public array $settings = [];

        /** @var Closure(object, object): void|null */
        private ?Closure $requestHandler = null;

        /** @var Closure(): void|null */
        private ?Closure $workerStart = null;

        public int $workerStops = 0;

        public function __construct(
            private readonly FakeSwooleRequest $request,
            private readonly FakeSwooleResponse $response,
        ) {}

        public function on(string $event, callable $handler): bool
        {
            if (strtolower($event) === 'workerstart') {
                $this->workerStart = Closure::fromCallable($handler);

                return true;
            }
            if (strtolower($event) === 'request') {
                $this->requestHandler = Closure::fromCallable($handler);

                return true;
            }

            return true;
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
            if ($this->requestHandler === null) {
                return false;
            }

            ($this->requestHandler)($this->request, $this->response);

            return true;
        }

        public function stop(int $workerId = -1, bool $waitEvent = false): bool
        {
            if ($workerId !== -1 || !$waitEvent) {
                return false;
            }

            ++$this->workerStops;

            return true;
        }
    };
    $application = new RuntimeApplication(
        static function (HttpRequest $request, ResponseWriterInterface $writer): void {
            unset($request, $writer);
        },
        static function (): void {
            throw new RuntimeException('reset isolation failed');
        },
    );
    $driver = new SwooleDriver(
        new SwooleOptions(),
        static fn(string $host, int $port): object => $host !== '' && $port > 0
            ? $server
            : throw new RuntimeException('Invalid Swoole test endpoint.'),
    );

    expect(fn() => $driver->run($application))
        ->toThrow(RequestLifecycleException::class)
        ->and($response->ends)->toBe(1)
        ->and($application->healthy())->toBeFalse()
        ->and($server->workerStops)->toBe(1);
});
