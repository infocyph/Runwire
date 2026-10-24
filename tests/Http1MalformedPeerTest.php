<?php

declare(strict_types=1);

use Infocyph\Runwire\Http\Client\ClientProtocolPolicy;
use Infocyph\Runwire\Http\Client\ClientRequest;
use Infocyph\Runwire\Http\Client\Http1ConnectedExchange;
use Infocyph\Runwire\Http\Enum\ProtocolVersion;
use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Network\Connection;
use Infocyph\Runwire\Network\Enum\ConnectionState;

it('aborts a connected HTTP/1.1 exchange on ambiguous response framing without pooling the socket', function (): void {
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    expect($pair)->toBeArray()->toHaveCount(2);
    [$socket, $peer] = $pair;
    stream_set_blocking($peer, false);
    $loop = new SelectLoop();
    $connection = new Connection($loop, $socket);
    $request = new ClientRequest(
        'GET',
        'http://example.test/',
        protocol: new ClientProtocolPolicy(ProtocolVersion::HTTP_1_1, [ProtocolVersion::HTTP_1_1], allowPlaintext: true),
    );
    $headers = 0;
    $body = '';
    $result = null;
    $failed = null;
    $readWatcher = $loop->onReadable($peer, static function ($stream, int $watcherId) use ($loop): void {
        $data = fread($stream, 8_192);
        if (is_string($data) && str_contains($data, "\r\n\r\n")) {
            $loop->cancel($watcherId);
            fwrite($stream, "HTTP/1.1 200 OK\r\nContent-Length: 2\r\nTransfer-Encoding: chunked\r\n\r\n2\r\nok\r\n0\r\n\r\n");
        }
    });
    $watchdog = $loop->delay(1.0, static function () use ($loop): void {
        $loop->stop();
    });
    $exchange = new Http1ConnectedExchange(
        $loop,
        $connection,
        $request,
        static function () use (&$headers): void {
            ++$headers;
        },
        static function (string $chunk) use (&$body): void {
            $body .= $chunk;
        },
        static function (?Connection $ready, ?Throwable $error) use (&$result, &$failed, $loop): void {
            $result = $ready;
            $failed = $error;
            $loop->stop();
        },
    );

    try {
        $exchange->start();
        $loop->run();
        expect($headers)->toBe(0)
            ->and($body)->toBe('')
            ->and($result)->toBeNull()
            ->and($failed)->toBeInstanceOf(RuntimeException::class)
            ->and($failed->getMessage())->toContain('Ambiguous')
            ->and($connection->state())->toBe(ConnectionState::CLOSED);
    } finally {
        $loop->cancel($readWatcher);
        $loop->cancel($watchdog);
        $exchange->close();
        $connection->abort();
        fclose($peer);
    }

    expect($loop->diagnostics()->readWatchers)->toBe(0)
        ->and($loop->diagnostics()->writeWatchers)->toBe(0)
        ->and($loop->diagnostics()->timersActive)->toBe(0);
});
