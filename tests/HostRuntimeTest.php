<?php

declare(strict_types=1);

use Infocyph\Runwire\FpmOptions;
use Infocyph\Runwire\FrankenPhpMode;
use Infocyph\Runwire\FrankenPhpOptions;
use Infocyph\Runwire\Http\Headers;
use Infocyph\Runwire\Http\HttpRequest;
use Infocyph\Runwire\Http\Internal\BufferedRequestBody;
use Infocyph\Runwire\Http\Internal\CallbackResponseWriter;
use Infocyph\Runwire\Http\ProtocolVersion;
use Infocyph\Runwire\Http\ResponseWriterInterface;
use Infocyph\Runwire\Network\WriteState;
use Infocyph\Runwire\Runtime\Driver\FpmDriver;
use Infocyph\Runwire\Runtime\Driver\FrankenPhpDriver;
use Infocyph\Runwire\Runtime\Host\HostRequestFactory;
use Infocyph\Runwire\Runtime\Host\RuntimeApplication;
use Infocyph\Runwire\Runtime\RuntimeEnvironment;
use Infocyph\Runwire\Runtime\RuntimeSelector;
use Infocyph\Runwire\RuntimeDriver;
use Infocyph\Runwire\RuntimeOptions;

function hostRuntimeRequest(string $target = '/host'): HttpRequest
{
    return new HttpRequest(
        method: 'GET',
        target: $target,
        version: ProtocolVersion::HTTP_1_1,
        headers: new Headers(),
        body: new BufferedRequestBody(''),
    );
}

function hostRuntimeWriter(array &$chunks): ResponseWriterInterface
{
    return new CallbackResponseWriter(
        static function (): void {},
        static function (string $chunk) use (&$chunks): void {
            $chunks[] = $chunk;
        },
        static function (): void {},
        1_024,
    );
}

it('normalizes host request metadata into the common HTTP request contract', function (): void {
    $request = (new HostRequestFactory())->fromServer([
        'REQUEST_METHOD' => 'POST',
        'REQUEST_URI' => '/submit?draft=1',
        'SERVER_PROTOCOL' => 'HTTP/2.0',
        'HTTP_X_TRACE_ID' => 'abc-123',
        'CONTENT_TYPE' => 'application/json',
        'REMOTE_ADDR' => '2001:db8::1',
        'REMOTE_PORT' => '12345',
        'SERVER_ADDR' => '127.0.0.1',
        'SERVER_PORT' => '443',
        'HTTPS' => 'on',
    ], '{"ok":true}', 1_024);

    expect($request->method)->toBe('POST')
        ->and($request->target)->toBe('/submit?draft=1')
        ->and($request->version)->toBe(ProtocolVersion::HTTP_2)
        ->and($request->headers->first('x-trace-id'))->toBe('abc-123')
        ->and($request->headers->first('content-type'))->toBe('application/json')
        ->and($request->peerAddress)->toBe('[2001:db8::1]:12345')
        ->and($request->localAddress)->toBe('127.0.0.1:443')
        ->and($request->encrypted)->toBeTrue()
        ->and($request->body->read())->toBe('{"ok":true}')
        ->and($request->body->eof())->toBeTrue();
});

it('enforces host response limits and common body suppression rules', function (): void {
    $headChunks = [];
    $headWriter = new CallbackResponseWriter(
        static function (): void {},
        static function (string $chunk) use (&$headChunks): void {
            $headChunks[] = $chunk;
        },
        static function (): void {},
        16,
        true,
    );
    $headResult = $headWriter->end('hidden');

    $limitedChunks = [];
    $limitedWriter = new CallbackResponseWriter(
        static function (): void {},
        static function (string $chunk) use (&$limitedChunks): void {
            $limitedChunks[] = $chunk;
        },
        static function (): void {},
        3,
    );
    $limitResult = $limitedWriter->write('four');

    expect($headResult->state)->toBe(WriteState::ACCEPTED)
        ->and($headChunks)->toBe([])
        ->and($limitResult->state)->toBe(WriteState::REJECTED_LIMIT)
        ->and($limitedChunks)->toBe([]);
});

it('runs FPM as one host-owned request with cleanup and shutdown', function (): void {
    $handled = 0;
    $cleaned = 0;
    $shutdown = 0;
    $chunks = [];
    $application = new RuntimeApplication(
        static function (HttpRequest $request, ResponseWriterInterface $writer) use (&$handled): void {
            ++$handled;
            $writer->end($request->target);
        },
        static function () use (&$cleaned): void {
            ++$cleaned;
        },
        static function () use (&$shutdown): void {
            ++$shutdown;
        },
    );
    $driver = new FpmDriver(
        new FpmOptions(),
        static fn(): HttpRequest => hostRuntimeRequest('/fpm'),
        static fn(string $method): ResponseWriterInterface => $method === 'GET'
            ? hostRuntimeWriter($chunks)
            : throw new RuntimeException('Unexpected host request method.'),
    );

    $driver->run($application);

    expect($handled)->toBe(1)
        ->and($cleaned)->toBe(1)
        ->and($shutdown)->toBe(1)
        ->and($chunks)->toBe(['/fpm']);
});

it('runs FrankenPHP classic mode as one request', function (): void {
    $handled = 0;
    $cleaned = 0;
    $shutdown = 0;
    $chunks = [];
    $application = new RuntimeApplication(
        static function (HttpRequest $request, ResponseWriterInterface $writer) use (&$handled): void {
            ++$handled;
            $writer->end($request->target);
        },
        static function () use (&$cleaned): void {
            ++$cleaned;
        },
        static function () use (&$shutdown): void {
            ++$shutdown;
        },
    );
    $driver = new FrankenPhpDriver(
        new FrankenPhpOptions(mode: FrankenPhpMode::CLASSIC),
        static fn(): HttpRequest => hostRuntimeRequest('/classic'),
        static fn(string $method): ResponseWriterInterface => $method === 'GET'
            ? hostRuntimeWriter($chunks)
            : throw new RuntimeException('Unexpected host request method.'),
    );

    $driver->run($application);

    expect($handled)->toBe(1)
        ->and($cleaned)->toBe(1)
        ->and($shutdown)->toBe(1)
        ->and($chunks)->toBe(['/classic']);
});

it('runs FrankenPHP worker requests with per-request cleanup and bounded recycling', function (): void {
    $handled = 0;
    $cleaned = 0;
    $shutdown = 0;
    $workerCalls = 0;
    $chunks = [];
    $application = new RuntimeApplication(
        static function (HttpRequest $request, ResponseWriterInterface $writer) use (&$handled): void {
            ++$handled;
            $writer->end($request->target);
        },
        static function () use (&$cleaned): void {
            ++$cleaned;
        },
        static function () use (&$shutdown): void {
            ++$shutdown;
        },
    );
    $driver = new FrankenPhpDriver(
        new FrankenPhpOptions(mode: FrankenPhpMode::WORKER, maxRequests: 2),
        static fn(): HttpRequest => hostRuntimeRequest('/worker'),
        static fn(string $method): ResponseWriterInterface => $method === 'GET'
            ? hostRuntimeWriter($chunks)
            : throw new RuntimeException('Unexpected host request method.'),
        static function (callable $handler) use (&$workerCalls): bool {
            ++$workerCalls;
            $handler();

            return true;
        },
    );

    $driver->run($application);

    expect($workerCalls)->toBe(2)
        ->and($handled)->toBe(2)
        ->and($cleaned)->toBe(2)
        ->and($shutdown)->toBe(1)
        ->and($chunks)->toBe(['/worker', '/worker']);
});

it('reports FrankenPHP worker persistence without claiming Runwire wire ownership', function (): void {
    $environment = new RuntimeEnvironment(
        sapi: 'frankenphp',
        hostedDrivers: [RuntimeDriver::FRANKENPHP],
        frankenPhpWorkerMode: true,
        opcacheAvailable: true,
    );
    $selection = (new RuntimeSelector())->select(
        new RuntimeOptions(
            driver: RuntimeDriver::FRANKENPHP,
            frankenPhp: new FrankenPhpOptions(mode: FrankenPhpMode::WORKER),
        ),
        $environment,
    );

    expect($selection->capabilities->persistentApplication)->toBeTrue()
        ->and($selection->capabilities->supportsHttp1)->toBeTrue()
        ->and($selection->capabilities->supportsHttp2)->toBeTrue()
        ->and($selection->capabilities->supportsHttp3)->toBeTrue()
        ->and($selection->capabilities->ownsHttp1Wire)->toBeFalse()
        ->and($selection->capabilities->ownsHttp2Wire)->toBeFalse()
        ->and($selection->capabilities->ownsHttp3Wire)->toBeFalse();
});
