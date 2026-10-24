<?php

declare(strict_types=1);

use Infocyph\Runwire\Http\Http1\Http1Connection;
use Infocyph\Runwire\Http\Http1\Http1Limits;
use Infocyph\Runwire\Http\HttpRequest;
use Infocyph\Runwire\Http\ResponseWriterInterface;
use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Network\Connection;

/** @return array{0: resource, 1: resource} */
function abuseHttp1Pair(): array
{
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    if (!is_array($pair)) {
        throw new RuntimeException('Unable to create HTTP/1 abuse socket pair.');
    }

    return $pair;
}

function abuseHttp1Exchange(string $wire, Http1Limits $limits, ?callable $handler = null): array
{
    [$server, $client] = abuseHttp1Pair();
    $loop = new SelectLoop();
    $called = 0;
    $handler ??= static function (HttpRequest $request, ResponseWriterInterface $writer): void {
        unset($request);
        $writer->end('ok');
    };
    new Http1Connection(
        $loop,
        new Connection($loop, $server),
        $limits,
        static function (HttpRequest $request, ResponseWriterInterface $writer) use ($handler, &$called): void {
            ++$called;
            $handler($request, $writer);
        },
    );
    fwrite($client, $wire);
    $loop->delay(0.15, static fn() => $loop->stop());
    $loop->run();
    stream_set_blocking($client, false);
    $response = stream_get_contents($client);
    fclose($client);

    return [$response, $called];
}

it('rejects oversized request lines and header lines before dispatch', function (): void {
    [$requestLine, $requestCalls] = abuseHttp1Exchange(
        'GET /' . str_repeat('x', 64) . " HTTP/1.1\r\nHost: x\r\n\r\n",
        new Http1Limits(maxRequestLineBytes: 32),
    );
    [$headerLine, $headerCalls] = abuseHttp1Exchange(
        "GET / HTTP/1.1\r\nX-Long: " . str_repeat('x', 64) . "\r\n\r\n",
        new Http1Limits(maxHeaderLineBytes: 32),
    );

    expect($requestCalls)->toBe(0)
        ->and($requestLine)->toStartWith('HTTP/1.1 414 URI Too Long')
        ->and($headerCalls)->toBe(0)
        ->and($headerLine)->toStartWith('HTTP/1.1 431 Request Header Fields Too Large');
});

it('rejects excessive aggregate headers and header counts before dispatch', function (): void {
    [$aggregate, $aggregateCalls] = abuseHttp1Exchange(
        "GET / HTTP/1.1\r\nA: 1234567890\r\nB: 1234567890\r\nC: 1234567890\r\n\r\n",
        new Http1Limits(maxHeaderBytes: 32),
    );
    [$count, $countCalls] = abuseHttp1Exchange(
        "GET / HTTP/1.1\r\nA: 1\r\nB: 2\r\nC: 3\r\n\r\n",
        new Http1Limits(maxHeaderCount: 2),
    );

    expect($aggregateCalls)->toBe(0)
        ->and($aggregate)->toStartWith('HTTP/1.1 431 Request Header Fields Too Large')
        ->and($countCalls)->toBe(0)
        ->and($count)->toStartWith('HTTP/1.1 431 Request Header Fields Too Large');
});

it('rejects oversized declared and chunked request bodies', function (): void {
    [$declared, $declaredCalls] = abuseHttp1Exchange(
        "POST / HTTP/1.1\r\nHost: x\r\nContent-Length: 9\r\n\r\n123456789",
        new Http1Limits(maxBodyBytes: 8),
    );
    [$chunked, $chunkedCalls] = abuseHttp1Exchange(
        "POST / HTTP/1.1\r\nHost: x\r\nTransfer-Encoding: chunked\r\n\r\n5\r\n12345\r\n4\r\n6789\r\n0\r\n\r\n",
        new Http1Limits(maxBodyBytes: 8),
        static function (HttpRequest $request): void {
            $request->body->onData(static fn($body) => $body->read());
        },
    );

    expect($declaredCalls)->toBe(0)
        ->and($declared)->toStartWith('HTTP/1.1 413 Content Too Large')
        ->and($chunkedCalls)->toBe(1)
        ->and($chunked)->toContain('HTTP/1.1 413 Content Too Large');
});

it('times out incomplete headers and stalled request bodies', function (): void {
    [$server, $client] = abuseHttp1Pair();
    $loop = new SelectLoop();
    new Http1Connection(
        $loop,
        new Connection($loop, $server),
        new Http1Limits(headerTimeoutSeconds: 0.02),
        static function (): void {},
    );
    fwrite($client, "GET / HTTP/1.1\r\nHost:");
    $loop->delay(0.06, static fn() => $loop->stop());
    $loop->run();
    stream_set_blocking($client, false);
    $headerResponse = stream_get_contents($client);
    fclose($client);

    [$server, $client] = abuseHttp1Pair();
    $loop = new SelectLoop();
    new Http1Connection(
        $loop,
        new Connection($loop, $server),
        new Http1Limits(bodyIdleTimeoutSeconds: 0.02),
        static function (HttpRequest $request): void {
            $request->body->onData(static fn($body) => $body->read());
        },
    );
    fwrite($client, "POST / HTTP/1.1\r\nHost: x\r\nContent-Length: 4\r\n\r\na");
    $loop->delay(0.06, static fn() => $loop->stop());
    $loop->run();
    stream_set_blocking($client, false);
    $bodyResponse = stream_get_contents($client);
    fclose($client);

    expect($headerResponse)->toStartWith('HTTP/1.1 408 Request Timeout')
        ->and($bodyResponse)->toContain('HTTP/1.1 408 Request Timeout');
});

it('enforces the keep-alive request ceiling on pipelined input', function (): void {
    $wire = '';
    for ($i = 0; $i < 4; ++$i) {
        $wire .= sprintf("GET /%d HTTP/1.1\r\nHost: x\r\n\r\n", $i);
    }

    [$response, $called] = abuseHttp1Exchange(
        $wire,
        new Http1Limits(maxKeepAliveRequests: 2),
    );

    expect($called)->toBe(2)
        ->and(substr_count($response, 'HTTP/1.1 200 OK'))->toBe(2);
});
