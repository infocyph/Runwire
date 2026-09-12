<?php

declare(strict_types=1);

use Infocyph\Runwire\FrankenPhpMode;
use Infocyph\Runwire\FrankenPhpOptions;
use Infocyph\Runwire\Http\Headers;
use Infocyph\Runwire\Http\HttpRequest;
use Infocyph\Runwire\Http\Internal\BufferedRequestBody;
use Infocyph\Runwire\Http\Internal\CallbackResponseWriter;
use Infocyph\Runwire\Http\ProtocolVersion;
use Infocyph\Runwire\Http\ResponseWriterInterface;
use Infocyph\Runwire\RoadRunnerOptions;
use Infocyph\Runwire\Runtime\Driver\FrankenPhpDriver;
use Infocyph\Runwire\Runtime\Driver\RoadRunnerDriver;
use Infocyph\Runwire\Runtime\Driver\SwooleDriver;
use Infocyph\Runwire\Runtime\Host\RoadRunnerSessionInterface;
use Infocyph\Runwire\Runtime\Host\RuntimeApplication;
use Infocyph\Runwire\SwooleOptions;
use Infocyph\Runwire\Tests\Fixtures\FakeSwooleRequest;
use Infocyph\Runwire\Tests\Fixtures\FakeSwooleResponse;
use RuntimeException;

function persistentRuntimeRequest(string $target): HttpRequest
{
    return new HttpRequest(
        method: 'GET',
        target: $target,
        version: ProtocolVersion::HTTP_1_1,
        headers: new Headers(),
        body: new BufferedRequestBody(''),
    );
}

function persistentRuntimeWriter(): ResponseWriterInterface
{
    return new CallbackResponseWriter(
        static function (int $status, Headers $headers): void {},
        static function (string $chunk): void {},
        static function (): void {},
        1_024,
    );
}

/**
 * @param list<?string> $seenBefore
 * @param list<string> $cleanedState
 */
function persistentRuntimeApplication(
    ?string &$state,
    array &$seenBefore,
    array &$cleanedState,
    int &$shutdowns,
): RuntimeApplication {
    return new RuntimeApplication(
        static function (HttpRequest $request, ResponseWriterInterface $writer) use (&$state, &$seenBefore): void {
            $seenBefore[] = $state;
            $state = $request->target;
            $writer->end($request->target);
        },
        static function () use (&$state, &$cleanedState): void {
            if ($state !== null) {
                $cleanedState[] = $state;
            }
            $state = null;
        },
        static function () use (&$shutdowns): void {
            ++$shutdowns;
        },
    );
}

it('always runs request cleanup when an application handler fails', function (): void {
    $state = null;
    $cleanups = 0;
    $application = new RuntimeApplication(
        static function () use (&$state): void {
            $state = 'dirty';
            throw new RuntimeException('handler failed');
        },
        static function () use (&$state, &$cleanups): void {
            $state = null;
            ++$cleanups;
        },
    );

    expect(static fn() => $application->handle(
        persistentRuntimeRequest('/failure'),
        persistentRuntimeWriter(),
    ))->toThrow(RuntimeException::class, 'handler failed')
        ->and($state)->toBeNull()
        ->and($cleanups)->toBe(1);
});

it('isolates consecutive FrankenPHP worker requests and recycles at the configured budget', function (): void {
    $state = null;
    $seenBefore = [];
    $cleanedState = [];
    $shutdowns = 0;
    $workerCalls = 0;
    $targets = ['/one', '/two'];
    $requestIndex = 0;
    $application = persistentRuntimeApplication($state, $seenBefore, $cleanedState, $shutdowns);
    $driver = new FrankenPhpDriver(
        new FrankenPhpOptions(mode: FrankenPhpMode::WORKER, maxRequests: 2),
        static function () use (&$targets, &$requestIndex): HttpRequest {
            return persistentRuntimeRequest($targets[$requestIndex++]);
        },
        static fn(string $method): ResponseWriterInterface => $method === 'GET'
            ? persistentRuntimeWriter()
            : throw new RuntimeException('Unexpected FrankenPHP request method.'),
        static function (callable $handler) use (&$workerCalls): bool {
            ++$workerCalls;
            $handler();

            return true;
        },
    );

    $driver->run($application);

    expect($seenBefore)->toBe([null, null])
        ->and($cleanedState)->toBe(['/one', '/two'])
        ->and($state)->toBeNull()
        ->and($workerCalls)->toBe(2)
        ->and($shutdowns)->toBe(1);
});

it('isolates consecutive RoadRunner requests and stops the persistent worker at its recycle budget', function (): void {
    $session = new class implements RoadRunnerSessionInterface {
        public int $stops = 0;

        /** @var list<HttpRequest> */
        private array $requests;

        private bool $stopped = false;

        public function __construct()
        {
            $this->requests = [
                persistentRuntimeRequest('/one'),
                persistentRuntimeRequest('/two'),
                persistentRuntimeRequest('/three'),
            ];
        }

        public function respond(int $status, string $body, array $headers, bool $endOfStream): void {}

        public function stop(): void
        {
            ++$this->stops;
            $this->stopped = true;
        }

        public function waitRequest(int $maxRequestBodyBytes): ?HttpRequest
        {
            if ($maxRequestBodyBytes < 1) {
                throw new RuntimeException('Unexpected RoadRunner request-body limit.');
            }
            if ($this->stopped) {
                return null;
            }

            return array_shift($this->requests);
        }
    };
    $state = null;
    $seenBefore = [];
    $cleanedState = [];
    $shutdowns = 0;
    $application = persistentRuntimeApplication($state, $seenBefore, $cleanedState, $shutdowns);
    $driver = new RoadRunnerDriver(
        new RoadRunnerOptions(maxRequests: 2),
        static fn(): RoadRunnerSessionInterface => $session,
    );

    $driver->run($application);

    expect($seenBefore)->toBe([null, null])
        ->and($cleanedState)->toBe(['/one', '/two'])
        ->and($state)->toBeNull()
        ->and($session->stops)->toBe(1)
        ->and($shutdowns)->toBe(1);
});

it('isolates consecutive Swoole requests while host recycling stays bounded by max_request', function (): void {
    $requests = [
        new FakeSwooleRequest([
            'request_method' => 'GET',
            'request_uri' => '/one',
            'server_protocol' => 'HTTP/1.1',
        ], [], ''),
        new FakeSwooleRequest([
            'request_method' => 'GET',
            'request_uri' => '/two',
            'server_protocol' => 'HTTP/1.1',
        ], [], ''),
    ];
    $responses = [new FakeSwooleResponse(), new FakeSwooleResponse()];
    $server = new class($requests, $responses) {
        /** @var array<string, bool|int> */
        public array $settings = [];

        /** @var \Closure(object, object): void|null */
        private ?\Closure $requestHandler = null;

        /**
         * @param list<FakeSwooleRequest> $requests
         * @param list<FakeSwooleResponse> $responses
         */
        public function __construct(
            private readonly array $requests,
            private readonly array $responses,
        ) {}

        public function on(string $event, callable $handler): bool
        {
            if (strtolower($event) !== 'request') {
                return false;
            }

            $this->requestHandler = \Closure::fromCallable($handler);

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
            if ($this->requestHandler === null) {
                return false;
            }

            foreach ($this->requests as $index => $request) {
                ($this->requestHandler)($request, $this->responses[$index]);
            }

            return true;
        }
    };
    $state = null;
    $seenBefore = [];
    $cleanedState = [];
    $shutdowns = 0;
    $application = persistentRuntimeApplication($state, $seenBefore, $cleanedState, $shutdowns);
    $driver = new SwooleDriver(
        new SwooleOptions(maxRequestsPerWorker: 2),
        static fn(string $host, int $port): object => $host !== '' && $port > 0
            ? $server
            : throw new RuntimeException('Invalid Swoole endpoint.'),
    );

    $driver->run($application);

    expect($seenBefore)->toBe([null, null])
        ->and($cleanedState)->toBe(['/one', '/two'])
        ->and($state)->toBeNull()
        ->and($server->settings['max_request'])->toBe(2)
        ->and($shutdowns)->toBe(1);
});
