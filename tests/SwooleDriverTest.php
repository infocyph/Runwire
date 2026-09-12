<?php

declare(strict_types=1);

use Infocyph\Runwire\Http\Enum\ProtocolVersion;
use Infocyph\Runwire\Http\Headers;
use Infocyph\Runwire\Http\HttpRequest;
use Infocyph\Runwire\Http\ResponseWriterInterface;
use Infocyph\Runwire\Runtime\Driver\SwooleDriver;
use Infocyph\Runwire\Runtime\Enum\RuntimeDriver;
use Infocyph\Runwire\Runtime\Host\RuntimeApplication;
use Infocyph\Runwire\Runtime\RuntimeEnvironment;
use Infocyph\Runwire\Runtime\RuntimeSelector;
use Infocyph\Runwire\RuntimeOptions;
use Infocyph\Runwire\Supervisor\WorkerRecyclePolicy;
use Infocyph\Runwire\SwooleOptions;
use Infocyph\Runwire\Tests\Fixtures\FakeSwooleRequest;
use Infocyph\Runwire\Tests\Fixtures\FakeSwooleResponse;
use Infocyph\Runwire\Tests\Fixtures\FakeSwooleServer;

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
        static fn(string $host, int $port): object => $server,
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

it('reports Swoole persistence without claiming Runwire HTTP wire ownership', function (): void {
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
        ->and($selection->capabilities->supportsCoroutines)->toBeTrue()
        ->and($selection->capabilities->supportsWorkerRecycle)->toBeTrue()
        ->and($selection->capabilities->supportsHttp1)->toBeTrue()
        ->and($selection->capabilities->supportsHttp2)->toBeTrue()
        ->and($selection->capabilities->supportsHttp3)->toBeFalse()
        ->and($selection->capabilities->ownsHttp1Wire)->toBeFalse()
        ->and($selection->capabilities->ownsHttp2Wire)->toBeFalse();
});
