<?php

declare(strict_types=1);

use Infocyph\Runwire\Http\Client\ClientProtocolPolicy;
use Infocyph\Runwire\Http\Client\ClientRequest;
use Infocyph\Runwire\Http\Client\Http1ConnectedExchange;
use Infocyph\Runwire\Http\Enum\ProtocolVersion;
use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Network\Connection;
use Infocyph\Runwire\Network\DialEndpoint;
use Infocyph\Runwire\Network\IdleConnectionPool;
use Infocyph\Runwire\Network\PoolIsolationKey;

it('reuses one verified H1 socket for two sequential framed responses without body bleed', function (): void {
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    expect($pair)->toBeArray()->toHaveCount(2);
    [$socket, $server] = $pair;
    stream_set_blocking($server, false);
    $loop = new SelectLoop();
    $connection = new Connection($loop, $socket);
    $pool = new IdleConnectionPool($loop, 1);
    $key = new PoolIsolationKey(DialEndpoint::parse('unix:///tmp/runwire-h1-reuse.sock'), 'worker-only');
    $policy = new ClientProtocolPolicy(ProtocolVersion::HTTP_1_1, [ProtocolVersion::HTTP_1_1], allowPlaintext: true);
    $status = [];
    $bodies = [];
    $seenWire = '';
    $requests = 0;
    $lastError = null;
    $watcher = $loop->onReadable($server, static function ($peer) use (&$seenWire, &$requests): void {
        $bytes = fread($peer, 8_192);
        if (is_string($bytes)) {
            $seenWire .= $bytes;
        }

        while (str_contains($seenWire, "\r\n\r\n") && $requests < 2) {
            $end = strpos($seenWire, "\r\n\r\n");
            $head = substr($seenWire, 0, $end + 4);
            $seenWire = substr($seenWire, $end + 4);
            ++$requests;
            if ($requests === 1 && str_contains($head, 'GET /one')) {
                fwrite($peer, "HTTP/1.1 200 OK\r\nContent-Length: 3\r\n\r\none");
            } elseif ($requests === 2 && str_contains($head, 'GET /two')) {
                fwrite($peer, "HTTP/1.1 200 OK\r\nContent-Length: 3\r\n\r\ntwo");
            }
        }
    });
    $watchdog = $loop->delay(1.0, static function () use ($loop): void {
        $loop->stop();
    });

    $send = null;
    $send = static function (string $path) use (
        $loop, $pool, $key, $policy, &$send, &$status, &$bodies, &$lastError,
    ): void {
        $candidate = $pool->take($key);
        expect($candidate)->toBeInstanceOf(Connection::class);
        $index = count($status);
        $bodies[$index] = '';
        $exchange = new Http1ConnectedExchange(
            $loop,
            $candidate,
            new ClientRequest('GET', 'http://example.test/' . $path, protocol: $policy),
            static function (int $code) use (&$status): void {
                $status[] = $code;
            },
            static function (string $chunk) use (&$bodies, $index): void {
                $bodies[$index] .= $chunk;
            },
            static function (?Connection $ready, ?Throwable $error) use ($pool, $key, $loop, &$send, &$lastError, $path): void {
                $lastError = $error;
                if ($error !== null || $ready === null) {
                    $loop->stop();

                    return;
                }
                expect($pool->store($key, $ready))->toBeTrue();
                if ($path === 'one') {
                    $loop->defer(static function () use (&$send): void {
                        $send('two');
                    });
                } else {
                    $loop->stop();
                }
            },
        );
        $exchange->start();
    };

    try {
        expect($pool->store($key, $connection))->toBeTrue();
        $send('one');
        $loop->run();
        expect($lastError)->toBeNull()
            ->and($requests)->toBe(2)
            ->and($status)->toBe([200, 200])
            ->and($bodies)->toBe(['one', 'two'])
            ->and($pool->count())->toBe(1);
    } finally {
        $loop->cancel($watcher);
        $loop->cancel($watchdog);
        $pool->close();
        $connection->abort();
        fclose($server);
    }

    expect($loop->diagnostics()->readWatchers)->toBe(0)
        ->and($loop->diagnostics()->writeWatchers)->toBe(0)
        ->and($loop->diagnostics()->timersActive)->toBe(0);
});
