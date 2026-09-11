<?php

declare(strict_types=1);

use Infocyph\Runwire\Http\Http2\Frame;
use Infocyph\Runwire\Http\Http2\FrameParser;
use Infocyph\Runwire\Http\Http2\FrameType;
use Infocyph\Runwire\Http\Http2\FrameWriter;
use Infocyph\Runwire\Http\Http2\Hpack\Encoder;
use Infocyph\Runwire\Http\Http2\Http2Connection;
use Infocyph\Runwire\Http\HttpRequest;
use Infocyph\Runwire\Http\NativeHttpConnection;
use Infocyph\Runwire\Http\ProtocolVersion;
use Infocyph\Runwire\Http\ResponseWriterInterface;
use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Network\CloseReason;
use Infocyph\Runwire\Network\Connection;
use Infocyph\Runwire\Network\ConnectionState;

it('selects HTTP/1.1 for cleartext native connections', function (): void {
    [$server, $client] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    $loop = new SelectLoop();
    $connection = new Connection($loop, $server);
    $version = null;

    $session = NativeHttpConnection::attach(
        $loop,
        $connection,
        static function (HttpRequest $request, ResponseWriterInterface $writer) use (&$version): void {
            $version = $request->version;
            $request->body->onEnd(static fn () => $writer->end('h1'));
        },
    );

    fwrite($client, "GET / HTTP/1.1\r\nHost: example.test\r\nConnection: close\r\n\r\n");
    $loop->delay(0.2, static fn () => $loop->stop());
    $loop->run();
    stream_set_blocking($client, false);
    $response = stream_get_contents($client);
    fclose($client);

    expect($session)->not->toBeNull()
        ->and($session->version)->toBe(ProtocolVersion::HTTP_1_1)
        ->and($version)->toBe(ProtocolVersion::HTTP_1_1)
        ->and($response)->toContain('h1');
});

it('dispatches negotiated h2 without HTTP/1 protocol sniffing', function (): void {
    [$server, $client] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    $loop = new SelectLoop();
    $connection = new Connection(
        $loop,
        $server,
        peerAddress: 'peer',
        localAddress: 'local',
        negotiatedProtocol: 'h2',
        encrypted: true,
    );
    $version = null;

    $session = NativeHttpConnection::attach(
        $loop,
        $connection,
        static function (HttpRequest $request, ResponseWriterInterface $writer) use (&$version): void {
            $version = $request->version;
            $request->body->onEnd(static fn () => $writer->end('h2'));
        },
    );

    $block = (new Encoder())->encode([
        [':method', 'GET'],
        [':scheme', 'https'],
        [':authority', 'example.test'],
        [':path', '/'],
    ]);
    fwrite(
        $client,
        Http2Connection::CLIENT_PREFACE
        . FrameWriter::encode(FrameWriter::settings())
        . FrameWriter::encode(new Frame(FrameType::HEADERS->value, 0x5, 1, $block)),
    );
    $loop->delay(0.2, static fn () => $loop->stop());
    $loop->run();
    stream_set_blocking($client, false);
    $wire = stream_get_contents($client);
    fclose($client);

    $body = '';
    foreach ((new FrameParser())->push($wire) as $frame) {
        if ($frame->knownType() === FrameType::DATA) {
            $body .= $frame->payload;
        }
    }

    expect($session)->not->toBeNull()
        ->and($session->version)->toBe(ProtocolVersion::HTTP_2)
        ->and($version)->toBe(ProtocolVersion::HTTP_2)
        ->and($body)->toBe('h2');
});

it('rejects unsupported negotiated protocols without failing the worker', function (): void {
    [$server, $client] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    $loop = new SelectLoop();
    $connection = new Connection($loop, $server, negotiatedProtocol: 'spdy/3', encrypted: true);

    $session = NativeHttpConnection::attach($loop, $connection, static function (): void {});
    fclose($client);

    expect($session)->toBeNull()
        ->and($connection->state())->toBe(ConnectionState::CLOSED)
        ->and($connection->closeReason())->toBe(CloseReason::PROTOCOL_ERROR);
});

it('drains an active HTTP/1.1 exchange without accepting another request', function (): void {
    [$server, $client] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    $loop = new SelectLoop();
    $connection = new Connection($loop, $server);
    $writer = null;
    $requests = 0;

    $session = NativeHttpConnection::attach(
        $loop,
        $connection,
        static function (HttpRequest $request, ResponseWriterInterface $response) use (&$writer, &$requests): void {
            ++$requests;
            $writer = $response;
        },
    );
    expect($session)->not->toBeNull();

    fwrite(
        $client,
        "GET /one HTTP/1.1\r\nHost: example.test\r\n\r\n"
        . "GET /two HTTP/1.1\r\nHost: example.test\r\n\r\n",
    );
    $loop->delay(0.03, static function () use ($session): void { $session->drain(); });
    $loop->delay(0.06, static function () use (&$writer): void { $writer?->end('done'); });
    $loop->delay(0.2, static fn () => $loop->stop());
    $loop->run();

    stream_set_blocking($client, false);
    $response = stream_get_contents($client);
    fclose($client);

    expect($requests)->toBe(1)
        ->and($response)->toContain('done')
        ->and(substr_count($response, 'HTTP/1.1 200'))->toBe(1)
        ->and($connection->state())->toBe(ConnectionState::CLOSED);
});
