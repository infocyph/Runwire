<?php

declare(strict_types=1);

use Infocyph\Runwire\Http\Enum\ProtocolVersion;
use Infocyph\Runwire\Http\Headers;
use Infocyph\Runwire\Http\Http1\Http1Connection;
use Infocyph\Runwire\Http\Http1\Http1Limits;
use Infocyph\Runwire\Http\Http2\Http2Limits;
use Infocyph\Runwire\Http\HttpRequest;
use Infocyph\Runwire\Http\Internal\BufferedRequestBody;
use Infocyph\Runwire\Http\Internal\CallbackResponseWriter;
use Infocyph\Runwire\Http\ResponseWriterInterface;
use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Network\Connection;
use Infocyph\Runwire\Runtime\Host\RuntimeApplication;

require_once __DIR__ . '/Support/Http2TestSupport.php';

/** @return array{memory: int, resources: int} */
function runwireSoakSnapshot(): array
{
    gc_collect_cycles();

    return [
        'memory' => memory_get_usage(true),
        'resources' => count(get_resources()),
    ];
}

/**
 * @throws \OverflowException
 */
function runwireSoakRequest(string $target): HttpRequest
{
    return new HttpRequest(
        method: 'GET',
        target: $target,
        version: ProtocolVersion::HTTP_1_1,
        headers: new Headers(),
        body: new BufferedRequestBody(''),
    );
}

/**
 * @throws \InvalidArgumentException
 */
function runwireSoakWriter(): ResponseWriterInterface
{
    return new CallbackResponseWriter(
        static function (): void {},
        static function (): void {},
        static function (): void {},
        1_024,
    );
}

/**
 * @return array{requests: int, responses: int}
 * @throws \RuntimeException
 * @throws \InvalidArgumentException
 * @throws \OverflowException
 * @throws \LogicException
 * @throws \Throwable
 */
function runwireSoakHttp1(int $requests): array
{
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    if ($pair === false) {
        throw new RuntimeException('Unable to create HTTP/1.1 soak socket pair.');
    }

    [$server, $client] = $pair;
    $loop = new SelectLoop();
    $connection = new Connection($loop, $server);
    $handled = 0;
    new Http1Connection(
        $loop,
        $connection,
        new Http1Limits(maxKeepAliveRequests: $requests + 1, maxParserStepsPerTick: $requests + 32),
        static function (HttpRequest $request, ResponseWriterInterface $writer) use (&$handled): void {
            ++$handled;
            $writer->end($request->target);
        },
    );

    $batchSize = 32;
    for ($batchStart = 0; $batchStart < $requests; $batchStart += $batchSize) {
        $batchEnd = min($requests, $batchStart + $batchSize);
        $wire = '';
        for ($index = $batchStart; $index < $batchEnd; ++$index) {
            $wire .= sprintf(
                "GET /soak/%d HTTP/1.1\r\nHost: example.test\r\n%s\r\n",
                $index,
                $index === $requests - 1 ? "Connection: close\r\n" : '',
            );
        }

        $written = fwrite($client, $wire);
        if ($written !== strlen($wire)) {
            throw new RuntimeException('HTTP/1.1 soak client could not write its bounded request batch.');
        }
        $loop->delay(0.1, static function () use ($loop): void {
    $loop->stop();
});
        $loop->run();
    }

    stream_set_blocking($client, false);
    $response = stream_get_contents($client);
    if (!is_string($response)) {
        throw new RuntimeException('Unable to read HTTP/1.1 soak responses.');
    }
    fclose($client);

    return [
        'requests' => $handled,
        'responses' => substr_count($response, 'HTTP/1.1 200 OK'),
    ];
}

/**
 * @return array{requests: int, active: int}
 * @throws \InvalidArgumentException
 * @throws \OverflowException
 * @throws \RuntimeException
 * @throws \LogicException
 * @throws \Throwable
 */
function runwireSoakHttp2(int $requests): array
{
    $wire = runwireH2ClientPrelude();
    for ($index = 0; $index < $requests; ++$index) {
        $wire .= runwireH2Headers(1 + ($index * 2), '/soak/' . $index);
    }

    $handled = 0;
    [, $connection] = runwireH2Exchange(
        $wire,
        static function (HttpRequest $request, ResponseWriterInterface $writer) use (&$handled): void {
            ++$handled;
            $writer->end($request->target);
        },
        new Http2Limits(
            maxConcurrentStreams: 32,
            maxStreamsPerConnection: $requests + 32,
            headerBlockTimeoutSeconds: 1.0,
        ),
    );

    return [
        'requests' => $handled,
        'active' => $connection->activeStreams(),
    ];
}

it('keeps protocol and request-scope state bounded under deterministic churn', function (): void {
    $warmup = runwireSoakSnapshot();
    expect($warmup['memory'])->toBeGreaterThan(0);
    $before = runwireSoakSnapshot();

    $http1 = runwireSoakHttp1(128);
    $http2 = runwireSoakHttp2(128);

    $requestState = new class { public ?string $target = null; };
    $cleanups = 0;
    $application = new RuntimeApplication(
        static function (HttpRequest $request, ResponseWriterInterface $writer) use ($requestState): void {
            if ($requestState->target !== null) {
                throw new RuntimeException('Persistent request state leaked across soak iterations.');
            }
            $requestState->target = $request->target;
            $writer->end('ok');
        },
        static function () use ($requestState, &$cleanups): void {
            $requestState->target = null;
            ++$cleanups;
        },
    );
    for ($index = 0; $index < 512; ++$index) {
        $application->handle(
            runwireSoakRequest('/scope/' . $index),
            runwireSoakWriter(),
        );
    }

    unset($application);
    $after = runwireSoakSnapshot();

    expect($http1)->toBe(['requests' => 128, 'responses' => 128])
        ->and($http2)->toBe(['requests' => 128, 'active' => 0])
        ->and($cleanups)->toBe(512)
        ->and($requestState->target)->toBeNull()
        ->and($after['resources'] - $before['resources'])->toBeLessThanOrEqual(4)
        ->and($after['memory'] - $before['memory'])->toBeLessThanOrEqual(16 * 1_024 * 1_024);
});
