<?php

declare(strict_types=1);

use Infocyph\Runwire\Http\Http2\Enum\FrameType;
use Infocyph\Runwire\Http\Http2\Frame;
use Infocyph\Runwire\Http\Http2\FrameParser;
use Infocyph\Runwire\Http\Http2\FrameWriter;
use Infocyph\Runwire\Http\Http2\Hpack\Encoder;
use Infocyph\Runwire\Http\Http2\Http2Connection;
use Infocyph\Runwire\Http\Http2\Http2Limits;
use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Network\Connection;

/**
 * @throws \InvalidArgumentException
 */
function runwireH2Headers(int $streamId, string $path = '/', bool $endStream = true): string
{
    $block = (new Encoder())->encode([
        [':method', 'GET'],
        [':scheme', 'https'],
        [':authority', 'example.test'],
        [':path', $path],
    ]);

    return FrameWriter::encode(new Frame(
        FrameType::HEADERS->value,
        0x4 | ($endStream ? 0x1 : 0),
        $streamId,
        $block,
    ));
}

/**
 * @throws \InvalidArgumentException
 */
function runwireH2ClientPrelude(array $settings = []): string
{
    return Http2Connection::CLIENT_PREFACE . FrameWriter::encode(FrameWriter::settings($settings));
}

/**
 * @return array{0: string, 1: Http2Connection}
 * @throws \InvalidArgumentException
 * @throws \OverflowException
 * @throws \RuntimeException
 * @throws \LogicException
 * @throws \Throwable
 */
function runwireH2Exchange(string $wire, callable $handler, ?Http2Limits $limits = null): array
{
    [$server, $client] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    $loop = new SelectLoop();
    $connection = new Connection($loop, $server);
    $http2 = new Http2Connection($loop, $connection, $limits ?? new Http2Limits(), $handler);

    fwrite($client, $wire);
    $loop->delay(0.2, static fn () => $loop->stop());
    $loop->run();
    stream_set_blocking($client, false);
    $response = stream_get_contents($client);
    fclose($client);

    return [$response, $http2];
}

/**
 * @return list<Frame>
 * @throws \InvalidArgumentException
 * @throws \OverflowException
 */
function runwireH2Frames(string $wire): array
{
    return (new FrameParser())->push($wire);
}
