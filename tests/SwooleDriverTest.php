<?php

declare(strict_types=1);

use Infocyph\Runwire\Http\Headers;
use Infocyph\Runwire\Http\HttpRequest;
use Infocyph\Runwire\Http\ProtocolVersion;
use Infocyph\Runwire\Http\ResponseWriterInterface;
use Infocyph\Runwire\Runtime\Driver\SwooleDriver;
use Infocyph\Runwire\Runtime\Host\RuntimeApplication;
use Infocyph\Runwire\Runtime\RuntimeEnvironment;
use Infocyph\Runwire\Runtime\RuntimeSelector;
use Infocyph\Runwire\RuntimeDriver;
use Infocyph\Runwire\RuntimeOptions;
use Infocyph\Runwire\SwooleOptions;

final class FakeSwooleRequest
{
    /**
     * @param array<string, mixed> $server
     * @param array<string, mixed> $header
     */
    public function __construct(
        public array $server,
        public array $header,
        private readonly string $body,
    ) {}

    public function rawContent(): string
    {
        return $this->body;
    }
}

final class FakeSwooleResponse
{
    /** @var list<string> */
    public array $chunks = [];

    public int $ends = 0;

    /** @var list<array{0: string, 1: string}> */
    public array $headers = [];

    /** @var list<int> */
    public array $statuses = [];

    public function end(): bool
    {
        ++$this->ends;

        return true;
    }

    public function header(string $name, string $value): bool
    {
        $this->headers[] = [$name, $value];

        return true;
    }

    public function status(int $status): bool
    {
        $this->statuses[] = $status;

        return true;
    }

    public function write(string $chunk): bool
    {
        $this->chunks[] = $chunk;

        return true;
    }
}

final class FakeSwooleServer
{
    /** @var array<string, bool|int> */
    public array $settings = [];

    public bool $shutdownCalled = false;

    /** @var Closure(): void|null */
    public ?\Closure $startHook = null;

    /** @var Closure(object, object): void|null */
    private ?\Closure $requestHandler = null;

    public function __construct(
        public readonly FakeSwooleRequest $request,
        public readonly FakeSwooleResponse $response,
    ) {}

    /** @param array<string, bool|int> $settings */
    public function set(array $settings): bool
    {
        $this->settings = $settings;

        return true;
    }

    public function on(string $event, callable $handler): bool
    {
        if (strtolower($event) !== 'request') {
            return false;
        }

        $this->requestHandler = \Closure::fromCallable($handler);

        return true;
    }

    public function shutdown(): bool
    {
        $this->shutdownCalled = true;

        return true;
    }

    public function start(): bool
    {
        if ($this->requestHandler === null) {
            return false;
        }

        ($this->requestHandler)($this->request, $this->response);
        ($this->startHook)?->__invoke();

        return true;
    }
}

it('validates and maps bounded Swoole server settings', function (): void {
    $options = new SwooleOptions(
        host: '::1',
        port: 9601,
        workerCount: 4,
        maxRequestsPerWorker: 500,
        maxRequestBodyBytes: 32_768,
        maxResponseBytes: 65_536,
        http2: true,
    );

    expect($options->serverSettings())->toBe([
        'package_max_length' => 65_536,
        'worker_num' => 4,
        'max_request' => 500,
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
        new SwooleOptions(host: '::1', port: 9502, workerCount: 2, maxRequestsPerWorker: 100, http2: true),
        static function (string $host, int $port) use (&$factoryHost, &$factoryPort, $server): object {
            $factoryHost = $host;
            $factoryPort = $port;

            return $server;
        },
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
        ->and($server->settings['open_http2_protocol'])->toBeTrue();
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
