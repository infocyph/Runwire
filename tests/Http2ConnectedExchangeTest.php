<?php

declare(strict_types=1);

use Infocyph\Runwire\Http\Client\ClientRequest;
use Infocyph\Runwire\Http\Client\Http2ConnectedExchange;
use Infocyph\Runwire\Http\Http2\Enum\FrameType;
use Infocyph\Runwire\Http\Http2\Frame;
use Infocyph\Runwire\Http\Http2\FrameParser;
use Infocyph\Runwire\Http\Http2\FrameWriter;
use Infocyph\Runwire\Http\Http2\Hpack\Decoder;
use Infocyph\Runwire\Http\Http2\Hpack\Encoder;
use Infocyph\Runwire\Http\Http2\Http2Connection;
use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Network\Connection;

it('delivers an H2 response over a separately adopted stream and releases every owner', function (): void {
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    expect($pair)->toBeArray()->toHaveCount(2);
    [$clientStream, $server] = $pair;
    stream_set_blocking($server, false);
    $loop = new SelectLoop();
    // Transport metadata is injected for the protocol-only Unix fixture.
    // Real TLS verification and ALPN remain independent dialer acceptance.
    $connection = new Connection($loop, $clientStream, negotiatedProtocol: 'h2', encrypted: true);
    $wire = '';
    $receivedRequest = [];
    $status = null;
    $body = '';
    $done = 0;
    $failure = null;
    $sentSettings = false;
    $returned = null;
    $peerFrames = new FrameParser();
    $serverWatcher = $loop->onReadable($server, static function ($peer) use (
        $loop, $server, &$wire, &$receivedRequest, &$sentSettings, $peerFrames,
    ): void {
        $chunk = fread($peer, 8_192);
        if (is_string($chunk)) {
            $wire .= $chunk;
        }
        if (!$sentSettings && str_starts_with($wire, Http2Connection::CLIENT_PREFACE)) {
            $wire = substr($wire, strlen(Http2Connection::CLIENT_PREFACE));
            fwrite($server, FrameWriter::encode(FrameWriter::settings()));
            $sentSettings = true;
        }
        if (!$sentSettings || $wire === '') {
            return;
        }
        $frames = $peerFrames->push($wire);
        $wire = '';
        foreach ($frames as $frame) {
            if ($frame->knownType() !== FrameType::HEADERS || $frame->streamId !== 1) {
                continue;
            }
            $receivedRequest = (new Decoder())->decode($frame->payload);
            $head = (new Encoder())->encode([[':status', '200'], ['content-type', 'text/plain']]);
            fwrite($server, FrameWriter::encode(new Frame(FrameType::HEADERS->value, 0x4, 1, $head))
                . FrameWriter::encode(new Frame(FrameType::DATA->value, 0x1, 1, 'good')));
        }
    });
    $watchdog = $loop->delay(2.0, static function () use ($loop): void { $loop->stop(); });
    $exchange = new Http2ConnectedExchange(
        $loop,
        $connection,
        new ClientRequest('GET', 'https://example.test/file?fresh=1'),
        static function (int $code) use (&$status): void { $status = $code; },
        static function (string $part) use (&$body): void { $body .= $part; },
        static function (?Connection $ready, ?Throwable $error) use (&$returned, &$failure, &$done, $loop): void {
            $returned = $ready;
            $failure = $error;
            ++$done;
            $loop->stop();
        },
    );
    try {
        $exchange->start();
        $loop->run();
        expect($receivedRequest)->toContain([':method', 'GET'], [':scheme', 'https'], [':path', '/file?fresh=1'])
            ->and($status)->toBe(200)
            ->and($body)->toBe('good')
            ->and($done)->toBe(1)
            ->and($failure)->toBeNull()
            ->and($returned)->toBeNull()
            ->and($connection->isPoolIdleSafe())->toBeFalse();
    } finally {
        $exchange->close();
        $loop->cancel($watchdog);
        $loop->cancel($serverWatcher);
        $connection->abort();
        fclose($server);
    }
    expect($loop->diagnostics()->readWatchers)->toBe(0)
        ->and($loop->diagnostics()->writeWatchers)->toBe(0)
        ->and($loop->diagnostics()->timersActive)->toBe(0);
});

it('rejects H2 on an unencrypted socket without publishing any unverified request', function (): void {
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    expect($pair)->toBeArray()->toHaveCount(2);
    [$clientStream, $server] = $pair;
    $loop = new SelectLoop();
    $connection = new Connection($loop, $clientStream, negotiatedProtocol: 'h2', encrypted: false);
    try {
        expect(fn() => new Http2ConnectedExchange(
            $loop,
            $connection,
            new ClientRequest('GET', 'https://example.test/private'),
            static function (): void {},
            static function (): void {},
            static function (): void {},
        ))->toThrow(InvalidArgumentException::class, 'verified TLS');
    } finally {
        $connection->abort();
        fclose($server);
    }
    expect($loop->diagnostics()->readWatchers)->toBe(0);
});
