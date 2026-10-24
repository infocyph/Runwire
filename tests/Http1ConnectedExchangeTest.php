<?php

declare(strict_types=1);

use Infocyph\Runwire\Http\Client\ClientProtocolPolicy;
use Infocyph\Runwire\Http\Client\ClientRequest;
use Infocyph\Runwire\Http\Client\Http1ConnectedExchange;
use Infocyph\Runwire\Http\Enum\ProtocolVersion;
use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Network\Connection;

it('streams an independent HTTP/1.1 server response without retaining a body or reusing an unsafe socket', function (): void {
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    expect($pair)->toBeArray()->toHaveCount(2);
    [$socket, $server] = $pair;
    stream_set_blocking($server, false);
    $loop = new SelectLoop();
    $connection = new Connection($loop, $socket);
    $request = new ClientRequest(
        'GET',
        'http://example.test/path?q=1',
        protocol: new ClientProtocolPolicy(
            preferred: ProtocolVersion::HTTP_1_1,
            allowedVersions: [ProtocolVersion::HTTP_1_1],
            allowPlaintext: true,
        ),
    );
    $status = null;
    $received = '';
    $result = null;
    $failure = null;
    $wire = '';
    $watcher = $loop->onReadable($server, static function ($peer, int $watcherId) use ($loop, &$wire): void {
        $chunk = fread($peer, 8_192);
        if (is_string($chunk)) {
            $wire .= $chunk;
        }
        if (str_contains($wire, "\r\n\r\n")) {
            $loop->cancel($watcherId);
            fwrite($peer, "HTTP/1.1 200 OK\r\nContent-Length: 5\r\n\r\nhello");
        }
    });
    $watchdog = $loop->delay(1.0, static function () use ($loop): void {
        $loop->stop();
    });
    $exchange = new Http1ConnectedExchange(
        $loop,
        $connection,
        $request,
        static function (int $code) use (&$status): void {
            $status = $code;
        },
        static function (string $chunk) use (&$received): void {
            $received .= $chunk;
        },
        static function (?Connection $returned, ?Throwable $error) use (&$result, &$failure, $loop): void {
            $result = $returned;
            $failure = $error;
            $loop->stop();
        },
    );

    try {
        $exchange->start();
        $loop->run();
        expect($wire)->toContain('GET /path?q=1 HTTP/1.1')
            ->and($status)->toBe(200)
            ->and($received)->toBe('hello')
            ->and($failure)->toBeNull()
            ->and($result)->toBe($connection)
            ->and($connection->isPoolIdleSafe())->toBeTrue();
    } finally {
        $loop->cancel($watcher);
        $loop->cancel($watchdog);
        $exchange->close();
        $connection->abort();
        fclose($server);
    }

    expect($loop->diagnostics()->readWatchers)->toBe(0)
        ->and($loop->diagnostics()->writeWatchers)->toBe(0)
        ->and($loop->diagnostics()->timersActive)->toBe(0);
});

it('rejects silent plaintext fallback when a secure URI is requested', function (): void {
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    expect($pair)->toBeArray()->toHaveCount(2);
    [$socket, $server] = $pair;
    $loop = new SelectLoop();
    $connection = new Connection($loop, $socket);
    $request = new ClientRequest(
        'GET',
        'https://example.test/',
        protocol: new ClientProtocolPolicy(ProtocolVersion::HTTP_1_1, [ProtocolVersion::HTTP_1_1]),
    );

    try {
        expect(fn() => new Http1ConnectedExchange(
            $loop,
            $connection,
            $request,
            static function (): void {},
            static function (): void {},
            static function (): void {},
        ))->toThrow(InvalidArgumentException::class);
    } finally {
        $connection->abort();
        fclose($server);
    }
});
