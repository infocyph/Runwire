<?php

declare(strict_types=1);

use Infocyph\Runwire\Http\Client\ClientBodySourceInterface;
use Infocyph\Runwire\Http\Client\ClientProtocolPolicy;
use Infocyph\Runwire\Http\Client\ClientRequest;
use Infocyph\Runwire\Http\Client\Http1ConnectedExchange;
use Infocyph\Runwire\Http\Enum\ProtocolVersion;
use Infocyph\Runwire\Http\Headers;
use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Network\Connection;
use Infocyph\Runwire\Network\ConnectionLimits;

it('uploads 128 KiB in bounded HTTP/1.1 chunks and parses a chunked reply over a real Unix socket', function (ConnectionLimits $limits): void {
    $payload = str_repeat("binary\x00\xff", 16_384);
    $bodySource = new class($payload) implements ClientBodySourceInterface {
        private int $offset = 0;

        public function __construct(private readonly string $bytes) {}

        public function eof(): bool
        {
            return $this->offset >= strlen($this->bytes);
        }

        public function read(int $maxBytes = PHP_INT_MAX): string
        {
            $slice = substr($this->bytes, $this->offset, $maxBytes);
            $this->offset += strlen($slice);

            return $slice;
        }

        public function trailers(): ?Headers
        {
            return null;
        }
    };
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    expect($pair)->toBeArray()->toHaveCount(2);
    [$clientStream, $server] = $pair;
    stream_set_blocking($server, false);
    $loop = new SelectLoop();
    $connection = new Connection($loop, $clientStream, $limits);
    $policy = new ClientProtocolPolicy(ProtocolVersion::HTTP_1_1, [ProtocolVersion::HTTP_1_1], allowPlaintext: true);
    $request = new ClientRequest('POST', 'http://example.test/upload', body: $bodySource, protocol: $policy);
    $serverBuffer = '';
    $uploadedHash = null;
    $reply = '';
    $error = null;
    $reusable = null;
    $watcher = $loop->onReadable($server, static function ($stream, int $watcherId) use (
        $loop, &$serverBuffer, &$uploadedHash,
    ): void {
        $chunk = fread($stream, 65_536);
        if (is_string($chunk)) {
            $serverBuffer .= $chunk;
        }
        $headerEnd = strpos($serverBuffer, "\r\n\r\n");
        if ($headerEnd === false || !str_ends_with($serverBuffer, "0\r\n\r\n")) {
            return;
        }

        $data = substr($serverBuffer, $headerEnd + 4);
        $ctx = hash_init('sha256');
        while (true) {
            $lineEnd = strpos($data, "\r\n");
            if ($lineEnd === false) {
                break;
            }
            $size = hexdec(substr($data, 0, $lineEnd));
            $data = substr($data, $lineEnd + 2);
            if ($size === 0) {
                break;
            }
            hash_update($ctx, substr($data, 0, $size));
            $data = substr($data, $size + 2);
        }
        $uploadedHash = hash_final($ctx);
        $loop->cancel($watcherId);
        fwrite($stream, "HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\n\r\n3\r\nok!\r\n0\r\n\r\n");
    });
    $watchdog = $loop->delay(1.0, static function () use ($loop): void {
        $loop->stop();
    });
    $exchange = new Http1ConnectedExchange(
        $loop,
        $connection,
        $request,
        static function (): void {},
        static function (string $chunk) use (&$reply): void {
            $reply .= $chunk;
        },
        static function (?Connection $candidate, ?Throwable $failure) use (&$reusable, &$error, $loop): void {
            $reusable = $candidate;
            $error = $failure;
            $loop->stop();
        },
    );

    try {
        $exchange->start();
        $loop->run();
        expect($uploadedHash)->toBe(hash('sha256', $payload))
            ->and($reply)->toBe('ok!')
            ->and($error)->toBeNull()
            ->and($reusable)->toBe($connection)
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
})->with([
    'default send queue' => [new ConnectionLimits()],
    'tight 8-KiB send queue' => [new ConnectionLimits(
        sendLowWatermarkBytes: 2_048,
        sendHighWatermarkBytes: 4_096,
        maxSendBufferBytes: 8_192,
    )],
]);
