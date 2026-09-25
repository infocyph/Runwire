<?php

declare(strict_types=1);

use Infocyph\Runwire\Http\HeaderField;
use Infocyph\Runwire\Http\Headers;
use Infocyph\Runwire\Http\Http1\Http1Connection;
use Infocyph\Runwire\Http\Http1\Http1Limits;
use Infocyph\Runwire\Http\HttpRequest;
use Infocyph\Runwire\Http\ResponseWriterInterface;
use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Network\Connection;
use Infocyph\Runwire\Network\ConnectionLimits;

function runwireHttpPair(): array
{
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    expect($pair)->toBeArray();

    return $pair;
}

function runwireHttpExchange(string $wire, callable $handler, ?Http1Limits $limits = null): string
{
    [$server, $client] = runwireHttpPair();
    $loop = new SelectLoop();
    $connection = new Connection($loop, $server);
    new Http1Connection($loop, $connection, $limits ?? new Http1Limits(), $handler);
    fwrite($client, $wire);
    $loop->delay(0.3, static fn () => $loop->stop());
    $loop->run();
    stream_set_blocking($client, false);
    $response = stream_get_contents($client);
    fclose($client);

    return $response;
}

it('serves a bounded HTTP/1.1 request through the version-neutral transport contract', function (): void {
    $response = runwireHttpExchange(
        "GET /hello?x=1 HTTP/1.1\r\nHost: example.test\r\nConnection: close\r\n\r\n",
        static function (HttpRequest $request, ResponseWriterInterface $writer): void {
            expect($request->method)->toBe('GET')
                ->and($request->target)->toBe('/hello?x=1')
                ->and($request->headers->first('host'))->toBe('example.test')
                ->and($request->version->value)->toBe('1.1');
            $writer->end('ok');
        },
    );

    expect($response)->toStartWith("HTTP/1.1 200 OK\r\n")
        ->and($response)->toEndWith("\r\n\r\nok");
});

it('streams fixed and chunked request bodies without exposing framing to the handler', function (): void {
    $fixed = '';
    $response = runwireHttpExchange(
        "POST /fixed HTTP/1.1\r\nHost: x\r\nContent-Length: 11\r\nConnection: close\r\n\r\nhello world",
        static function (HttpRequest $request, ResponseWriterInterface $writer) use (&$fixed): void {
            $request->body->onData(static function ($body) use (&$fixed): void { $fixed .= $body->read(); });
            $request->body->onEnd(static fn () => $writer->end('fixed-ok'));
        },
    );

    $chunked = '';
    $trailer = null;
    $chunkedResponse = runwireHttpExchange(
        "POST /chunked HTTP/1.1\r\nHost: x\r\nTransfer-Encoding: chunked\r\nConnection: close\r\n\r\n5\r\nhello\r\n6\r\n world\r\n0\r\nX-End: yes\r\n\r\n",
        static function (HttpRequest $request, ResponseWriterInterface $writer) use (&$chunked, &$trailer): void {
            $request->body->onData(static function ($body) use (&$chunked): void { $chunked .= $body->read(); });
            $request->body->onEnd(static function ($body) use (&$trailer, $writer): void {
                $trailer = $body->trailers()?->first('x-end');
                $writer->end('chunked-ok');
            });
        },
    );

    expect($fixed)->toBe('hello world')
        ->and($response)->toEndWith("\r\n\r\nfixed-ok")
        ->and($chunked)->toBe('hello world')
        ->and($trailer)->toBe('yes')
        ->and($chunkedResponse)->toEndWith("\r\n\r\nchunked-ok");
});

it('rejects request-smuggling framing before application dispatch', function (): void {
    $called = false;
    $response = runwireHttpExchange(
        "POST /bad HTTP/1.1\r\nHost: x\r\nContent-Length: 4\r\nTransfer-Encoding: chunked\r\n\r\n",
        static function () use (&$called): void { $called = true; },
    );

    expect($called)->toBeFalse()
        ->and($response)->toStartWith('HTTP/1.1 400 Bad Request');
});

it('continues parsing already-buffered input after exhausting the parser step budget', function (): void {
    $response = runwireHttpExchange(
        "GET /budget HTTP/1.1\r\nHost: x\r\nConnection: close\r\n\r\n",
        static function (HttpRequest $request, ResponseWriterInterface $writer): void {
            expect($request->target)->toBe('/budget');
            $writer->end('ok');
        },
        new Http1Limits(maxParserStepsPerTick: 1),
    );

    expect($response)->toStartWith('HTTP/1.1 200 OK')
        ->and($response)->toEndWith("\r\n\r\nok");
});

it('rejects empty Transfer-Encoding fields before application dispatch', function (string $transferEncoding): void {
    $called = false;
    $response = runwireHttpExchange(
        "POST /bad HTTP/1.1\r\nHost: x\r\nContent-Length: 1\r\nTransfer-Encoding: {$transferEncoding}\r\nConnection: close\r\n\r\nx",
        static function () use (&$called): void {
            $called = true;
        },
    );

    expect($called)->toBeFalse()
        ->and($response)->toStartWith('HTTP/1.1 400 Bad Request');
})->with([
    'empty' => '',
    'whitespace' => '   ',
    'comma-only' => ' , ',
]);

it('preserves pipelined response order and enforces the keep-alive ceiling', function (): void {
    $count = 0;
    $response = runwireHttpExchange(
        "GET /1 HTTP/1.1\r\nHost: x\r\n\r\nGET /2 HTTP/1.1\r\nHost: x\r\nConnection: close\r\n\r\n",
        static function (HttpRequest $request, ResponseWriterInterface $writer) use (&$count): void {
            ++$count;
            $writer->end($request->target);
        },
    );

    expect($count)->toBe(2)
        ->and(substr_count($response, 'HTTP/1.1 200 OK'))->toBe(2)
        ->and($response)->toContain("\r\n\r\n/1HTTP/1.1 200 OK")
        ->and($response)->toEndWith("\r\n\r\n/2");
});

it('handles fully fragmented chunked input', function (): void {
    [$server, $client] = runwireHttpPair();
    $loop = new SelectLoop();
    $connection = new Connection($loop, $server, new ConnectionLimits(
        readChunkBytes: 1,
        maxReadBytesPerTick: 32,
        receiveLowWatermarkBytes: 64,
        receiveHighWatermarkBytes: 128,
        maxReceiveBufferBytes: 256,
        sendLowWatermarkBytes: 64,
        sendHighWatermarkBytes: 128,
        maxSendBufferBytes: 16_384,
        maxWriteBytesPerTick: 64,
    ));
    $body = '';
    new Http1Connection($loop, $connection, new Http1Limits(), static function (HttpRequest $request, ResponseWriterInterface $writer) use (&$body): void {
        $request->body->onData(static function ($stream) use (&$body): void { $body .= $stream->read(); });
        $request->body->onEnd(static fn () => $writer->end('ok'));
    });

    fwrite($client, "POST / HTTP/1.1\r\nHost: x\r\nTransfer-Encoding: chunked\r\nConnection: close\r\n\r\n3\r\nabc\r\n2\r\nde\r\n0\r\n\r\n");
    $loop->delay(1.0, static fn () => $loop->stop());
    $loop->run();
    stream_set_blocking($client, false);
    $response = stream_get_contents($client);
    fclose($client);

    expect($body)->toBe('abcde')->and($response)->toEndWith("\r\n\r\nok");
});

it('rejects malformed framing, fragments in request targets and premature EOF', function (): void {
    foreach ([
        "POST / HTTP/1.1\r\nHost: x\r\nTransfer-Encoding: gzip, chunked\r\n\r\n",
        "GET / HTTP/1.1\r\nHost: x\r\n X: folded\r\n\r\n",
        "GET /bad#fragment HTTP/1.1\r\nHost: x\r\n\r\n",
    ] as $wire) {
        expect(runwireHttpExchange($wire, static function (): void {}))->toStartWith('HTTP/1.1 400 Bad Request');
    }

    [$server, $client] = runwireHttpPair();
    $loop = new SelectLoop();
    $connection = new Connection($loop, $server);
    new Http1Connection($loop, $connection, new Http1Limits(), static function (HttpRequest $request): void {
        $request->body->onData(static fn ($body) => $body->read());
    });
    fwrite($client, "POST /short HTTP/1.1\r\nHost: x\r\nContent-Length: 5\r\n\r\nab");
    stream_socket_shutdown($client, STREAM_SHUT_WR);
    $loop->delay(0.3, static fn () => $loop->stop());
    $loop->run();
    stream_set_blocking($client, false);
    $response = stream_get_contents($client);
    fclose($client);

    expect($response)->toStartWith('HTTP/1.1 400 Bad Request');
});

it('rejects invalid request-target forms and conflicting authorities', function (): void {
    foreach ([
        "GET * HTTP/1.1\r\nHost: example.test\r\n\r\n",
        "CONNECT /not-authority HTTP/1.1\r\nHost: example.test\r\n\r\n",
        "POST example.test:443 HTTP/1.1\r\nHost: example.test\r\n\r\n",
        "GET http://example.test/path HTTP/1.1\r\nHost: other.test\r\n\r\n",
        "GET / HTTP/1.1\r\nHost: bad host\r\n\r\n",
    ] as $wire) {
        expect(runwireHttpExchange($wire, static function (): void {}))
            ->toStartWith('HTTP/1.1 400 Bad Request');
    }
});

it('accepts valid OPTIONS asterisk CONNECT and absolute request targets', function (): void {
    foreach ([
        "OPTIONS * HTTP/1.1\r\nHost: example.test\r\nConnection: close\r\n\r\n",
        "CONNECT example.test:443 HTTP/1.1\r\nHost: example.test:443\r\nConnection: close\r\n\r\n",
        "GET https://example.test:443/path HTTP/1.1\r\nHost: example.test\r\nConnection: close\r\n\r\n",
    ] as $wire) {
        expect(runwireHttpExchange(
            $wire,
            static fn(HttpRequest $request, ResponseWriterInterface $writer) => $writer->end($request->target),
        ))->toStartWith('HTTP/1.1 200 OK');
    }
});

it('suppresses HTTP 205 response content', function (): void {
    $response = runwireHttpExchange(
        "GET /reset HTTP/1.1\r\nHost: example.test\r\nConnection: close\r\n\r\n",
        static function (HttpRequest $request, ResponseWriterInterface $writer): void {
            expect($request->target)->toBe('/reset');
            $writer->start(205, Headers::fromArray(['content-length' => '0']));
            $writer->end('hidden');
        },
    );

    expect($response)->toStartWith('HTTP/1.1 205 Reset Content')
        ->and($response)->not->toContain('hidden');
});

it('emits 100 Continue before the final response', function (): void {
    $response = runwireHttpExchange(
        "POST /continue HTTP/1.1\r\nHost: x\r\nExpect: 100-continue\r\nContent-Length: 4\r\nConnection: close\r\n\r\ntest",
        static function (HttpRequest $request, ResponseWriterInterface $writer): void {
            $request->body->onData(static fn ($body) => $body->read());
            $request->body->onEnd(static fn () => $writer->end('ok'));
        },
    );

    expect($response)->toStartWith("HTTP/1.1 100 Continue\r\n\r\nHTTP/1.1 200 OK\r\n")
        ->and($response)->toEndWith("\r\n\r\nok");
});

it('enforces declared response Content-Length', function (): void {
    expect(fn () => runwireHttpExchange(
        "GET / HTTP/1.1\r\nHost: x\r\nConnection: close\r\n\r\n",
        static function (HttpRequest $request, ResponseWriterInterface $writer): void {
            expect($request->target)->toBe('/');
            $writer->start(200, new Headers([new HeaderField('Content-Length', '2')]));
            $writer->end('abc');
        },
    ))->toThrow(LogicException::class);
});
