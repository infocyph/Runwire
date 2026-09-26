<?php

declare(strict_types=1);

use Infocyph\Runwire\Http\Http1\Http1Connection;
use Infocyph\Runwire\Http\Http1\Http1Limits;
use Infocyph\Runwire\Http\Http2\Enum\FrameType as Http2FrameType;
use Infocyph\Runwire\Http\Http2\Frame as Http2Frame;
use Infocyph\Runwire\Http\Http2\FrameWriter as Http2FrameWriter;
use Infocyph\Runwire\Http\Http2\Hpack\Encoder as HpackEncoder;
use Infocyph\Runwire\Http\Http3\Enum\FrameType as Http3FrameType;
use Infocyph\Runwire\Http\Http3\Frame as Http3Frame;
use Infocyph\Runwire\Http\Http3\Http3ResponseWriter;
use Infocyph\Runwire\Http\Http3\Http3Session;
use Infocyph\Runwire\Http\Http3\Internal\ConnectionState as Http3ConnectionState;
use Infocyph\Runwire\Http\Http3\Qpack\Encoder as QpackEncoder;
use Infocyph\Runwire\Http\HttpRequest;
use Infocyph\Runwire\Http\ResponseWriterInterface;
use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Network\Connection;
use Infocyph\Runwire\Network\Enum\WriteState;
use Infocyph\Runwire\Network\WriteResult;

require_once __DIR__ . '/Support/Http2TestSupport.php';

/**
 * @param array<string, mixed> $snapshot
 * @return Closure(HttpRequest, ResponseWriterInterface): void
 */
function semanticParityHandler(array &$snapshot): Closure
{
    return static function (HttpRequest $request, ResponseWriterInterface $writer) use (&$snapshot): void {
        $snapshot = [
            'method' => $request->method,
            'target' => $request->target,
            'host' => $request->headers->first('host'),
            'x-shared' => $request->headers->first('x-shared'),
            'body' => '',
            'trailer' => null,
            'version' => $request->version->value,
            'encrypted' => $request->encrypted,
        ];
        $request->body->onData(static function ($body) use (&$snapshot): void {
            $snapshot['body'] .= $body->read();
        });
        $request->body->onEnd(static function ($body) use (&$snapshot, $writer): void {
            $snapshot['trailer'] = $body->trailers()?->first('x-trailer');
            $writer->end('ok');
        });
    };
}

/** @param array<string, mixed> $snapshot */
function semanticParityHttp1(array &$snapshot): void
{
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    if ($pair === false) {
        throw new RuntimeException('Unable to create HTTP/1.1 semantic-parity socket pair.');
    }
    [$server, $client] = $pair;
    $loop = new SelectLoop();
    $connection = new Connection($loop, $server);
    new Http1Connection($loop, $connection, new Http1Limits(), semanticParityHandler($snapshot));

    fwrite(
        $client,
        "POST /parity?x=1 HTTP/1.1\r\n"
        . "Host: example.test\r\n"
        . "X-Shared: same\r\n"
        . "Transfer-Encoding: chunked\r\n"
        . "Connection: close\r\n\r\n"
        . "5\r\nhello\r\n"
        . "6\r\n world\r\n"
        . "0\r\nX-Trailer: done\r\n\r\n",
    );
    $loop->delay(0.3, static fn() => $loop->stop());
    $loop->run();
    fclose($client);
}

/** @param array<string, mixed> $snapshot */
function semanticParityHttp2(array &$snapshot): void
{
    $encoder = new HpackEncoder();
    $initial = $encoder->encode([
        [':method', 'POST'],
        [':scheme', 'https'],
        [':authority', 'example.test'],
        [':path', '/parity?x=1'],
        ['x-shared', 'same'],
    ]);
    $trailers = $encoder->encode([
        ['x-trailer', 'done'],
    ]);
    $wire = runwireH2ClientPrelude()
        . Http2FrameWriter::encode(new Http2Frame(Http2FrameType::HEADERS->value, 0x4, 1, $initial))
        . Http2FrameWriter::encode(new Http2Frame(Http2FrameType::DATA->value, 0, 1, 'hello world'))
        . Http2FrameWriter::encode(new Http2Frame(Http2FrameType::HEADERS->value, 0x5, 1, $trailers));

    runwireH2Exchange($wire, semanticParityHandler($snapshot));
}

function semanticParityHttp3Writer(int $streamId, string $method): ResponseWriterInterface
{
    if ($streamId < 0 || $method === '') {
        throw new InvalidArgumentException('Invalid HTTP/3 semantic-parity writer arguments.');
    }

    return new Http3ResponseWriter(
        $method,
        static fn(): WriteResult => new WriteResult(WriteState::ACCEPTED, 0),
        static fn(): WriteResult => new WriteResult(WriteState::ACCEPTED, 0),
        static function (): void {},
        static function (): void {},
    );
}

/** @param array<string, mixed> $snapshot */
function semanticParityHttp3(array &$snapshot): void
{
    $session = new Http3Session(
        new Http3ConnectionState(),
        semanticParityHandler($snapshot),
        semanticParityHttp3Writer(...),
    );
    $encoder = new QpackEncoder(0, 0);
    $initial = $encoder->encode([
        [':method', 'POST'],
        [':scheme', 'https'],
        [':authority', 'example.test'],
        [':path', '/parity?x=1'],
        ['x-shared', 'same'],
    ], 0)->block;
    $trailers = $encoder->encode([
        ['x-trailer', 'done'],
    ], 0)->block;

    $session->pushRequestStream(0, new Http3Frame(Http3FrameType::HEADERS->value, $initial)->encode());
    $session->pushRequestStream(0, new Http3Frame(Http3FrameType::DATA->value, 'hello world')->encode());
    $session->pushRequestStream(0, new Http3Frame(Http3FrameType::HEADERS->value, $trailers)->encode());
    $session->finishRequestStream(0);
}

it('preserves the application-visible request contract across HTTP/1.1, HTTP/2 and HTTP/3', function (): void {
    $http1 = [];
    $http2 = [];
    $http3 = [];

    semanticParityHttp1($http1);
    semanticParityHttp2($http2);
    semanticParityHttp3($http3);

    $applicationKeys = ['method', 'target', 'host', 'x-shared', 'body', 'trailer'];
    $http1Contract = array_intersect_key($http1, array_flip($applicationKeys));
    $http2Contract = array_intersect_key($http2, array_flip($applicationKeys));
    $http3Contract = array_intersect_key($http3, array_flip($applicationKeys));

    expect($http1Contract)->toBe([
        'method' => 'POST',
        'target' => '/parity?x=1',
        'host' => 'example.test',
        'x-shared' => 'same',
        'body' => 'hello world',
        'trailer' => 'done',
    ])->and($http2Contract)->toBe($http1Contract)
        ->and($http3Contract)->toBe($http1Contract)
        ->and($http1['version'])->toBe('1.1')
        ->and($http2['version'])->toBe('2')
        ->and($http3['version'])->toBe('3')
        ->and($http1['encrypted'])->toBeFalse()
        ->and($http2['encrypted'])->toBeFalse()
        ->and($http3['encrypted'])->toBeTrue();
});
