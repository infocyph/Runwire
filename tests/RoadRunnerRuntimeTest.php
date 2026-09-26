<?php

declare(strict_types=1);

use Infocyph\Runwire\Http\Enum\ProtocolVersion;
use Infocyph\Runwire\Http\Headers;
use Infocyph\Runwire\Http\HttpRequest;
use Infocyph\Runwire\Http\Internal\BufferedRequestBody;
use Infocyph\Runwire\Http\ResponseWriterInterface;
use Infocyph\Runwire\RoadRunnerOptions;
use Infocyph\Runwire\Runtime\Driver\RoadRunnerDriver;
use Infocyph\Runwire\Runtime\Enum\RuntimeDriver;
use Infocyph\Runwire\Runtime\Host\RoadRunnerResponseWriter;
use Infocyph\Runwire\Runtime\Host\RoadRunnerSession;
use Infocyph\Runwire\Runtime\Host\RoadRunnerSessionInterface;
use Infocyph\Runwire\Runtime\Host\RuntimeApplication;
use Infocyph\Runwire\Runtime\RuntimeEnvironment;
use Infocyph\Runwire\Runtime\RuntimeSelector;
use Infocyph\Runwire\RuntimeOptions;
use Infocyph\Runwire\Supervisor\WorkerRecyclePolicy;

function roadRunnerRuntimeRequest(string $target): HttpRequest
{
    return new HttpRequest(
        method: 'GET',
        target: $target,
        version: ProtocolVersion::HTTP_2,
        headers: Headers::fromArray(['x-source' => 'roadrunner']),
        body: new BufferedRequestBody(''),
    );
}

it('normalizes RoadRunner host requests into the common HTTP contract', function (): void {
    $hostRequest = new class {
        public string $body = '{"ok":true}';

        /** @var array<string, list<string>> */
        public array $headers = [
            'x-trace-id' => ['one', 'two'],
            'content-type' => ['application/json'],
        ];

        public string $method = 'POST';

        public string $protocol = 'HTTP/3.0';

        public string $remoteAddr = '203.0.113.7:44321';

        public string $uri = 'https://example.test/rr?q=1';

        public function getRemoteAddr(): string
        {
            return $this->remoteAddr;
        }
    };
    $httpWorker = new class($hostRequest) {
        private ?object $request;

        public function __construct(object $request)
        {
            $this->request = $request;
        }

        public function waitRequest(): ?object
        {
            $request = $this->request;
            $this->request = null;

            return $request;
        }
    };
    $worker = new class {
        public int $stops = 0;

        public function stop(): void
        {
            ++$this->stops;
        }
    };

    $request = new RoadRunnerSession($worker, $httpWorker)->waitRequest(1_024);

    expect($request)->not->toBeNull()
        ->and($request?->method)->toBe('POST')
        ->and($request?->target)->toBe('/rr?q=1')
        ->and($request?->version)->toBe(ProtocolVersion::HTTP_3)
        ->and($request?->headers->all('x-trace-id'))->toBe(['one', 'two'])
        ->and($request?->body->read())->toBe('{"ok":true}')
        ->and($request?->peerAddress)->toBe('203.0.113.7:44321')
        ->and($request?->encrypted)->toBeTrue();
});

it('streams RoadRunner response frames and recycles after the generic request budget', function (): void {
    $session = new class implements RoadRunnerSessionInterface {
        /** @var list<array{status: int, body: string, headers: array<string, list<string>>, end: bool}> */
        public array $responses = [];

        public int $stops = 0;

        private int $index = 0;

        /** @var list<HttpRequest> */
        private array $requests;

        private bool $stopped = false;

        public function __construct()
        {
            $this->requests = [
                roadRunnerRuntimeRequest('/one'),
                roadRunnerRuntimeRequest('/two'),
                roadRunnerRuntimeRequest('/three'),
            ];
        }

        public function respond(int $status, string $body, array $headers, bool $endOfStream): void
        {
            $this->responses[] = [
                'status' => $status,
                'body' => $body,
                'headers' => $headers,
                'end' => $endOfStream,
            ];
        }

        public function stop(): void
        {
            $this->stopped = true;
            ++$this->stops;
        }

        public function waitRequest(int $maxRequestBodyBytes): ?HttpRequest
        {
            if ($maxRequestBodyBytes < 1) {
                throw new RuntimeException('Unexpected RoadRunner request limit.');
            }
            if ($this->stopped || !isset($this->requests[$this->index])) {
                return null;
            }

            return $this->requests[$this->index++];
        }
    };
    $handled = 0;
    $cleaned = 0;
    $shutdown = 0;
    $application = new RuntimeApplication(
        static function (HttpRequest $request, ResponseWriterInterface $writer) use (&$handled): void {
            ++$handled;
            $writer->start(201, Headers::fromArray(['x-frame' => ['one', 'two']]));
            $writer->write($request->target . ':');
            $writer->end('done');
        },
        static function () use (&$cleaned): void {
            ++$cleaned;
        },
        static function () use (&$shutdown): void {
            ++$shutdown;
        },
    );
    $driver = new RoadRunnerDriver(
        new RoadRunnerOptions(maxResponseBytes: 1_024),
        static fn(): RoadRunnerSessionInterface => $session,
        recyclePolicy: new WorkerRecyclePolicy(maxRequests: 2),
    );

    $driver->run($application);

    expect($handled)->toBe(2)
        ->and($cleaned)->toBe(2)
        ->and($shutdown)->toBe(1)
        ->and($session->stops)->toBe(1)
        ->and($session->responses)->toHaveCount(4)
        ->and($session->responses[0])->toBe([
            'status' => 201,
            'body' => '/one:',
            'headers' => ['x-frame' => ['one', 'two']],
            'end' => false,
        ])
        ->and($session->responses[1])->toBe([
            'status' => 201,
            'body' => 'done',
            'headers' => ['x-frame' => ['one', 'two']],
            'end' => true,
        ]);
});

it('suppresses RoadRunner HEAD response body frames', function (): void {
    $session = new class implements RoadRunnerSessionInterface {
        /** @var list<array{status: int, body: string, headers: array<string, list<string>>, end: bool}> */
        public array $responses = [];

        public function respond(int $status, string $body, array $headers, bool $endOfStream): void
        {
            $this->responses[] = [
                'status' => $status,
                'body' => $body,
                'headers' => $headers,
                'end' => $endOfStream,
            ];
        }

        public function stop(): void {}

        public function waitRequest(int $maxRequestBodyBytes): ?HttpRequest
        {
            if ($maxRequestBodyBytes < 1) {
                throw new RuntimeException('Unexpected RoadRunner request limit.');
            }

            return null;
        }
    };
    $writer = new RoadRunnerResponseWriter($session, 64, true);

    $writer->end('hidden');

    expect($session->responses)->toBe([[
        'status' => 200,
        'body' => '',
        'headers' => [],
        'end' => true,
    ]]);
});

it('reports RoadRunner host protocols without claiming Runwire wire ownership', function (): void {
    $environment = new RuntimeEnvironment(
        sapi: 'cli',
        hostedDrivers: [RuntimeDriver::ROADRUNNER],
        opcacheAvailable: true,
        opcacheCliEnabled: true,
    );
    $selection = new RuntimeSelector()->select(
        new RuntimeOptions(driver: RuntimeDriver::ROADRUNNER),
        $environment,
    );

    expect($selection->capabilities->persistentApplication)->toBeTrue()
        ->and($selection->capabilities->supportsWorkerRecycle)->toBeTrue()
        ->and($selection->capabilities->supportsHttp1)->toBeTrue()
        ->and($selection->capabilities->supportsHttp2)->toBeTrue()
        ->and($selection->capabilities->supportsHttp3)->toBeTrue()
        ->and($selection->capabilities->supportsQuic)->toBeTrue()
        ->and($selection->capabilities->ownsHttp1Wire)->toBeFalse()
        ->and($selection->capabilities->ownsHttp2Wire)->toBeFalse()
        ->and($selection->capabilities->ownsHttp3Wire)->toBeFalse();
});


it('enforces declared Content-Length in RoadRunner response writers', function (): void {
    $session = new class implements RoadRunnerSessionInterface {
        public array $responses = [];

        public function respond(int $status, string $body, array $headers, bool $endOfStream): void
        {
            $this->responses[] = [$status, $body, $headers, $endOfStream];
        }

        public function stop(): void {}

        public function waitRequest(int $maxRequestBodyBytes): ?HttpRequest
        {
            unset($maxRequestBodyBytes);

            return null;
        }
    };

    $writer = new RoadRunnerResponseWriter($session, 64);
    $writer->start(200, Headers::fromArray(['content-length' => '2']));

    expect(fn () => $writer->end('abc'))
        ->toThrow(LogicException::class, 'exceeds declared Content-Length')
        ->and($session->responses)->toBe([]);

    $short = new RoadRunnerResponseWriter($session, 64);
    $short->start(200, Headers::fromArray(['content-length' => '3']));
    $short->write('ab');

    expect(fn () => $short->end())
        ->toThrow(LogicException::class, 'shorter than declared Content-Length')
        ->and($session->responses)->toHaveCount(1);
});
