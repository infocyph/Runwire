<?php

declare(strict_types=1);

use Infocyph\Runwire\Http\Http2\Enum\FrameType;
use Infocyph\Runwire\Http\Http2\Frame;
use Infocyph\Runwire\Http\Http2\FrameWriter;
use Infocyph\Runwire\Http\Http2\Hpack\Decoder;
use Infocyph\Runwire\Http\Http2\Hpack\Encoder;
use Infocyph\Runwire\Http\Http2\Http2Connection;
use Infocyph\Runwire\Http\Http2\Http2Limits;
use Infocyph\Runwire\Http\HttpRequest;
use Infocyph\Runwire\Http\ResponseWriterInterface;
use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Network\Connection;
use Infocyph\Runwire\Network\ConnectionLimits;

require_once __DIR__ . '/Support/Http2TestSupport.php';

it('serves a basic HTTP/2 exchange through the version-neutral transport contract', function (): void {
    $seen = [];
    [$wire] = runwireH2Exchange(
        runwireH2ClientPrelude() . runwireH2Headers(1, '/hello'),
        static function (HttpRequest $request, ResponseWriterInterface $writer) use (&$seen): void {
            $seen = [
                $request->method,
                $request->target,
                $request->headers->first('host'),
                $request->version->value,
            ];
            $request->body->onEnd(static fn () => $writer->end('hello-h2'));
        },
    );

    $decoder = new Decoder();
    $headers = null;
    $data = '';
    foreach (runwireH2Frames($wire) as $frame) {
        if ($frame->knownType() === FrameType::HEADERS) {
            $headers = $decoder->decode($frame->payload);
        }
        if ($frame->knownType() === FrameType::DATA) {
            $data .= $frame->payload;
        }
    }

    expect($seen)->toBe(['GET', '/hello', 'example.test', '2'])
        ->and($headers)->toContain([':status', '200'])
        ->and($data)->toBe('hello-h2');
});

it('keeps sibling streams independent when one stream exhausts its send window', function (): void {
    $settings = [\Infocyph\Runwire\Http\Http2\PeerSettings::INITIAL_WINDOW_SIZE => 5];
    [$wire] = runwireH2Exchange(
        runwireH2ClientPrelude($settings)
            . runwireH2Headers(1, '/slow')
            . runwireH2Headers(3, '/fast'),
        static function (HttpRequest $request, ResponseWriterInterface $writer): void {
            $writer->end($request->target === '/slow' ? '1234567890' : 'abcdefghij');
        },
    );

    $payloads = [1 => '', 3 => ''];
    foreach (runwireH2Frames($wire) as $frame) {
        if ($frame->knownType() === FrameType::DATA && isset($payloads[$frame->streamId])) {
            $payloads[$frame->streamId] .= $frame->payload;
        }
    }

    expect($payloads[1])->toBe('12345')->and($payloads[3])->toBe('abcde');
});

it('replenishes request flow-control credit only after body consumption', function (): void {
    $block = (new Encoder())->encode([
        [':method', 'POST'],
        [':scheme', 'https'],
        [':authority', 'example.test'],
        [':path', '/body'],
        ['content-length', '32000'],
    ]);
    $wire = runwireH2ClientPrelude()
        . FrameWriter::encode(new Frame(FrameType::HEADERS->value, 0x4, 1, $block))
        . FrameWriter::encode(new Frame(FrameType::DATA->value, 0, 1, str_repeat('x', 16_000)))
        . FrameWriter::encode(new Frame(FrameType::DATA->value, 0x1, 1, str_repeat('y', 16_000)));

    $seen = 0;
    [$response] = runwireH2Exchange($wire, static function (HttpRequest $request, ResponseWriterInterface $writer) use (&$seen): void {
        $request->body->onData(static function ($body) use (&$seen): void {
            $seen += strlen($body->read());
        });
        $request->body->onEnd(static fn () => $writer->end('ok'));
    });

    $updates = [];
    foreach (runwireH2Frames($response) as $frame) {
        if ($frame->knownType() === FrameType::WINDOW_UPDATE) {
            $updates[] = $frame->streamId;
        }
    }

    expect($seen)->toBe(32_000)->and($updates)->toContain(0)->and($updates)->toContain(1);
});

it('honors fragmented transport input while retaining protocol state', function (): void {
    [$server, $client] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    $loop = new SelectLoop();
    $connection = new Connection($loop, $server, new ConnectionLimits(
        readChunkBytes: 1,
        maxReadBytesPerTick: 32,
        receiveLowWatermarkBytes: 64,
        receiveHighWatermarkBytes: 128,
        maxReceiveBufferBytes: 256,
        sendLowWatermarkBytes: 64,
        sendHighWatermarkBytes: 128,
        maxSendBufferBytes: 65_536,
        maxWriteBytesPerTick: 64,
    ));
    $seen = false;
    new Http2Connection($loop, $connection, new Http2Limits(), static function (HttpRequest $request, ResponseWriterInterface $writer) use (&$seen): void {
        $seen = $request->target === '/fragmented';
        $request->body->onEnd(static fn () => $writer->end('ok'));
    });

    fwrite($client, runwireH2ClientPrelude() . runwireH2Headers(1, '/fragmented'));
    $loop->delay(0.8, static fn () => $loop->stop());
    $loop->run();
    fclose($client);

    expect($seen)->toBeTrue();
});


it('does not expose unexpected callback exception text in HTTP/2 GOAWAY debug data', function (): void {
    $secret = 'tenant-secret-' . bin2hex(random_bytes(4));
    $headers = (new Encoder())->encode([
        [':method', 'POST'],
        [':scheme', 'https'],
        [':authority', 'example.test'],
        [':path', '/callback-failure'],
    ]);
    $wire = runwireH2ClientPrelude()
        . FrameWriter::encode(new Frame(FrameType::HEADERS->value, 0x4, 1, $headers))
        . FrameWriter::encode(new Frame(FrameType::DATA->value, 0x1, 1, 'x'));

    [$response] = runwireH2Exchange($wire, static function (HttpRequest $request) use ($secret): void {
        $request->body->onData(static function () use ($secret): void {
            throw new RuntimeException($secret);
        });
    });

    $debug = '';
    foreach (runwireH2Frames($response) as $frame) {
        if ($frame->knownType() === FrameType::GOAWAY) {
            $debug .= substr($frame->payload, 8);
        }
    }

    expect($debug)->not->toContain($secret);
});
