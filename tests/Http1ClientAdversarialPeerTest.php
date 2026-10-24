<?php

declare(strict_types=1);

use Infocyph\Runwire\Http\Client\ClientProtocolPolicy;
use Infocyph\Runwire\Http\Client\ClientRequest;
use Infocyph\Runwire\Http\Client\Http1ClientResponseParser;
use Infocyph\Runwire\Http\Client\Http1ConnectedExchange;
use Infocyph\Runwire\Http\Enum\ProtocolVersion;
use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Network\Connection;

it('rejects an adversarial response framing corpus before publishing headers', function (): void {
    for ($index = 1; $index <= 32; ++$index) {
        $headCount = 0;
        $bodyCount = 0;
        $parser = new Http1ClientResponseParser();
        $wire = "HTTP/1.1 200 OK\r\n"
            . 'Content-Length: ' . $index . "\r\n"
            . "Transfer-Encoding: chunked\r\n\r\n";
        expect(fn() => $parser->push(
            $wire,
            static function () use (&$headCount): void {
                ++$headCount;
            },
            static function () use (&$bodyCount): void {
                ++$bodyCount;
            },
            static function (): void {},
        ))->toThrow(RuntimeException::class, 'Ambiguous');
        expect($headCount)->toBe(0)
            ->and($bodyCount)->toBe(0)
            ->and($parser->done())->toBeFalse();
    }
});

it('streams a binary one-megabyte independent H1 response without retaining the body', function (): void {
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    expect($pair)->toBeArray()->toHaveCount(2);
    [$socket, $server] = $pair;
    stream_set_blocking($server, false);

    $loop = new SelectLoop();
    $connection = new Connection($loop, $socket);
    $payload = str_repeat("\x00sample\xffstream", 65_536);
    $total = strlen($payload);
    $response = "HTTP/1.1 200 OK\r\nContent-Length: " . $total . "\r\n\r\n" . $payload;
    $wireOffset = 0;
    $status = null;
    $bodyBytes = 0;
    $maxChunk = 0;
    $hash = hash_init('sha256');
    $completed = null;
    $failure = null;
    $writeWatcher = null;

    $readWatcher = $loop->onReadable($server, static function ($peer, int $watcherId) use (
        $loop,
        &$writeWatcher,
        $response,
        &$wireOffset,
    ): void {
        $chunk = fread($peer, 8_192);
        if (!is_string($chunk) || !str_contains($chunk, "\r\n\r\n")) {
            return;
        }
        $loop->cancel($watcherId);
        $writeWatcher = $loop->onWritable($peer, static function ($stream, int $id) use (
            $loop,
            $response,
            &$wireOffset,
        ): void {
            if ($wireOffset >= strlen($response)) {
                $loop->cancel($id);

                return;
            }
            $count = fwrite($stream, substr($response, $wireOffset, 8_192));
            if (is_int($count) && $count > 0) {
                $wireOffset += $count;
            }
        });
    });
    $watchdog = $loop->delay(5.0, static function () use ($loop): void {
        $loop->stop();
    });
    $exchange = new Http1ConnectedExchange(
        $loop,
        $connection,
        new ClientRequest('GET', 'http://example.test/large', protocol: new ClientProtocolPolicy(
            ProtocolVersion::HTTP_1_1,
            [ProtocolVersion::HTTP_1_1],
            allowPlaintext: true,
        )),
        static function (int $code) use (&$status): void {
            $status = $code;
        },
        static function (string $bytes) use (&$bodyBytes, &$maxChunk, $hash): void {
            $bodyBytes += strlen($bytes);
            $maxChunk = max($maxChunk, strlen($bytes));
            hash_update($hash, $bytes);
        },
        static function (?Connection $ready, ?Throwable $error) use (&$completed, &$failure, $loop): void {
            $completed = $ready;
            $failure = $error;
            $loop->stop();
        },
    );

    try {
        $exchange->start();
        $loop->run();
        expect($status)->toBe(200)
            ->and($bodyBytes)->toBe($total)
            ->and(hash_final($hash))->toBe(hash('sha256', $payload))
            ->and($maxChunk)->toBeLessThanOrEqual(65_536)
            ->and($failure)->toBeNull()
            ->and($completed)->toBe($connection)
            ->and($connection->isPoolIdleSafe())->toBeTrue();
    } finally {
        $loop->cancel($readWatcher);
        if (is_int($writeWatcher)) {
            $loop->cancel($writeWatcher);
        }
        $loop->cancel($watchdog);
        $exchange->close();
        $connection->abort();
        fclose($server);
    }
    expect($loop->diagnostics()->readWatchers)->toBe(0)
        ->and($loop->diagnostics()->writeWatchers)->toBe(0)
        ->and($loop->diagnostics()->timersActive)->toBe(0);
});
