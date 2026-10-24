<?php

declare(strict_types=1);

use Infocyph\Runwire\Executor\ProcessExecutor;
use Infocyph\Runwire\Http\Client\ClientProtocolPolicy;
use Infocyph\Runwire\Http\Client\ClientRequest;
use Infocyph\Runwire\Http\Client\Http1OutboundClient;
use Infocyph\Runwire\Http\Enum\ProtocolVersion;
use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Network\AsyncDnsResolver;
use Infocyph\Runwire\Network\Connection;
use Infocyph\Runwire\Network\Dialer;
use Infocyph\Runwire\Network\HappyEyeballsDialer;

it('connects a real independent numeric TCP peer and streams H1 response with no hidden fallback', function (): void {
    $listener = stream_socket_server('tcp://127.0.0.1:0', $code, $error);
    expect($listener)->toBeResource();
    stream_set_blocking($listener, false);
    $address = stream_socket_get_name($listener, false);
    expect($address)->toBeString();

    $loop = new SelectLoop();
    $executor = new ProcessExecutor($loop, 1, 1);
    $dialer = new Dialer($loop);
    $hostDialer = new HappyEyeballsDialer($loop, $dialer, new AsyncDnsResolver($executor));
    $request = new ClientRequest(
        'GET',
        'http://' . $address . '/ready',
        protocol: new ClientProtocolPolicy(ProtocolVersion::HTTP_1_1, [ProtocolVersion::HTTP_1_1], allowPlaintext: true),
    );
    $peer = null;
    $peerWatcher = null;
    $status = null;
    $body = '';
    $reusable = null;
    $failure = null;
    $acceptWatcher = $loop->onReadable($listener, static function () use ($listener, $loop, &$peer, &$peerWatcher): void {
        $accepted = stream_socket_accept($listener, 0);
        if (!is_resource($accepted)) {
            return;
        }
        stream_set_blocking($accepted, false);
        $peer = $accepted;
        $peerWatcher = $loop->onReadable($peer, static function ($stream, int $watcherId) use ($loop): void {
            $bytes = fread($stream, 8_192);
            if (is_string($bytes) && str_contains($bytes, "\r\n\r\n")) {
                $loop->cancel($watcherId);
                fwrite($stream, "HTTP/1.1 200 OK\r\nContent-Length: 7\r\n\r\nready!!");
            }
        });
    });
    $watchdog = $loop->delay(1.0, static function () use ($loop): void {
        $loop->stop();
    });

    try {
        Http1OutboundClient::connect(
            $loop,
            $hostDialer,
            $request,
            static function (int $code) use (&$status): void {
                $status = $code;
            },
            static function (string $chunk) use (&$body): void {
                $body .= $chunk;
            },
            static function (?Connection $connection, ?Throwable $error) use (&$reusable, &$failure, $loop): void {
                $reusable = $connection;
                $failure = $error;
                $loop->stop();
            },
        );
        $loop->run();
        expect($status)->toBe(200)
            ->and($body)->toBe('ready!!')
            ->and($failure)->toBeNull()
            ->and($reusable)->toBeInstanceOf(Connection::class)
            ->and($reusable->isPoolIdleSafe())->toBeTrue();
    } finally {
        $loop->cancel($acceptWatcher);
        $loop->cancel($watchdog);
        if (is_int($peerWatcher)) {
            $loop->cancel($peerWatcher);
        }
        $reusable?->abort();
        $hostDialer->close();
        $dialer->close();
        $executor->close();
        if (is_resource($peer)) {
            fclose($peer);
        }
        fclose($listener);
    }

    expect($loop->diagnostics()->readWatchers)->toBe(0)
        ->and($loop->diagnostics()->writeWatchers)->toBe(0)
        ->and($loop->diagnostics()->timersActive)->toBe(0);
});
