<?php

declare(strict_types=1);

use Infocyph\Runwire\Executor\ProcessExecutor;
use Infocyph\Runwire\Http\Client\ClientProtocolPolicy;
use Infocyph\Runwire\Http\Client\ClientRedirectRetryPolicy;
use Infocyph\Runwire\Http\Client\ClientRequest;
use Infocyph\Runwire\Http\Client\Http1PolicyClient;
use Infocyph\Runwire\Http\Enum\ProtocolVersion;
use Infocyph\Runwire\Http\Headers;
use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Network\AsyncDnsResolver;
use Infocyph\Runwire\Network\Connection;
use Infocyph\Runwire\Network\Dialer;
use Infocyph\Runwire\Network\HappyEyeballsDialer;

it('follows one bounded independent H1 redirect without forwarding cross-origin credentials', function (): void {
    $first = stream_socket_server('tcp://127.0.0.1:0', $code, $reason);
    $second = stream_socket_server('tcp://127.0.0.1:0', $code, $reason);
    expect($first)->toBeResource()
        ->and($second)->toBeResource();
    stream_set_blocking($first, false);
    stream_set_blocking($second, false);
    $firstAddress = stream_socket_get_name($first, false);
    $secondAddress = stream_socket_get_name($second, false);
    expect($firstAddress)->toBeString()
        ->and($secondAddress)->toBeString();

    $loop = new SelectLoop();
    $executor = new ProcessExecutor($loop, 1, 1);
    $numeric = new Dialer($loop);
    $dialer = new HappyEyeballsDialer($loop, $numeric, new AsyncDnsResolver($executor));
    $servers = [];
    $peerWatchers = [];
    $requests = ['first' => '', 'second' => ''];
    $statuses = [];
    $body = '';
    $completed = null;
    $failed = null;

    $register = static function ($listener, string $name) use (
        $loop,
        &$servers,
        &$peerWatchers,
        &$requests,
        $secondAddress,
    ): int {
        return $loop->onReadable($listener, static function ($stream) use (
            $loop,
            $name,
            &$servers,
            &$peerWatchers,
            &$requests,
            $secondAddress,
        ): void {
            $peer = stream_socket_accept($stream, 0);
            if (!is_resource($peer)) {
                return;
            }
            stream_set_blocking($peer, false);
            $servers[] = $peer;
            $peerWatchers[] = $loop->onReadable($peer, static function ($client, int $id) use (
                $loop,
                $name,
                &$requests,
                $secondAddress,
            ): void {
                $chunk = fread($client, 8_192);
                if (is_string($chunk)) {
                    $requests[$name] .= $chunk;
                }
                if (!str_contains($requests[$name], "\r\n\r\n")) {
                    return;
                }
                $loop->cancel($id);
                if ($name === 'first') {
                    fwrite($client, "HTTP/1.1 302 Found\r\n"
                        . 'Location: http://' . $secondAddress . "/new\r\n"
                        . "Content-Length: 0\r\n\r\n");

                    return;
                }
                fwrite($client, "HTTP/1.1 200 OK\r\nContent-Length: 4\r\n\r\ndone");
            });
        });
    };

    $listenerA = $register($first, 'first');
    $listenerB = $register($second, 'second');
    $watchdog = $loop->delay(2.0, static function () use ($loop): void {
        $loop->stop();
    });
    $request = new ClientRequest(
        'GET',
        'http://' . $firstAddress . '/original',
        Headers::fromArray([
            'authorization' => 'Bearer private',
            'cookie' => 'session=confidential',
            'x-tenant' => 'sensitive',
            'accept' => 'text/plain',
        ]),
        protocol: new ClientProtocolPolicy(
            ProtocolVersion::HTTP_1_1,
            [ProtocolVersion::HTTP_1_1],
            allowPlaintext: true,
        ),
    );
    $client = new Http1PolicyClient(
        $loop,
        $dialer,
        $request,
        new ClientRedirectRetryPolicy(maxRedirects: 1, maxRetries: 0, allowCrossOrigin: true),
        static function (int $status) use (&$statuses): void {
            $statuses[] = $status;
        },
        static function (string $chunk) use (&$body): void {
            $body .= $chunk;
        },
        static function (?Connection $connection, ?Throwable $error) use (&$completed, &$failed, $loop): void {
            $completed = $connection;
            $failed = $error;
            $loop->stop();
        },
    );

    try {
        $client->start();
        $loop->run();
        expect($failed)->toBeNull()
            ->and($statuses)->toBe([200])
            ->and($body)->toBe('done')
            ->and($requests['first'])->toContain('authorization: Bearer private')
            ->and($requests['first'])->toContain('cookie: session=confidential')
            ->and($requests['second'])->toContain('GET /new HTTP/1.1')
            ->and(strtolower($requests['second']))->not->toContain('authorization:')
            ->and(strtolower($requests['second']))->not->toContain('cookie:')
            ->and(strtolower($requests['second']))->not->toContain('x-tenant:')
            ->and($completed)->toBeInstanceOf(Connection::class)
            ->and($completed->isPoolIdleSafe())->toBeTrue();
    } finally {
        $completed?->abort();
        $loop->cancel($watchdog);
        $loop->cancel($listenerA);
        $loop->cancel($listenerB);
        foreach ($peerWatchers as $id) {
            $loop->cancel($id);
        }
        foreach ($servers as $peer) {
            if (is_resource($peer)) {
                fclose($peer);
            }
        }
        $dialer->close();
        $numeric->close();
        $executor->close();
        fclose($first);
        fclose($second);
    }

    expect($loop->diagnostics()->readWatchers)->toBe(0)
        ->and($loop->diagnostics()->writeWatchers)->toBe(0)
        ->and($loop->diagnostics()->timersActive)->toBe(0);
});
