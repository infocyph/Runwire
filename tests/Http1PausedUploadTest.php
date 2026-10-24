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

it('resumes a temporarily starved upload without reactor spinning or retaining socket ownership', function (): void {
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    expect($pair)->toBeArray()->toHaveCount(2);
    [$clientSocket, $serverSocket] = $pair;
    stream_set_blocking($serverSocket, false);

    $source = new class implements ClientBodySourceInterface {
        private bool $ready = false;

        private bool $sent = false;

        public function eof(): bool
        {
            return $this->sent;
        }

        public function read(int $maxBytes = PHP_INT_MAX): string
        {
            if (!$this->ready || $this->sent) {
                return '';
            }
            $this->sent = true;

            return substr('fragment', 0, $maxBytes);
        }

        public function release(): void
        {
            $this->ready = true;
        }

        public function trailers(): ?Headers
        {
            return null;
        }
    };

    $loop = new SelectLoop();
    $connection = new Connection($loop, $clientSocket);
    $request = new ClientRequest(
        'POST',
        'http://example.test/slow',
        body: $source,
        protocol: new ClientProtocolPolicy(
            ProtocolVersion::HTTP_1_1,
            [ProtocolVersion::HTTP_1_1],
            allowPlaintext: true,
        ),
    );
    $receivedWire = '';
    $headers = null;
    $body = '';
    $completed = null;
    $failure = null;
    $resumed = false;
    $watcher = $loop->onReadable($serverSocket, static function ($stream, int $watcherId) use (
        $loop,
        &$receivedWire,
    ): void {
        $chunk = fread($stream, 8_192);
        if (is_string($chunk)) {
            $receivedWire .= $chunk;
        }
        if (!str_contains($receivedWire, "0\r\n\r\n")) {
            return;
        }
        $loop->cancel($watcherId);
        fwrite($stream, "HTTP/1.1 200 OK\r\nContent-Length: 2\r\n\r\nok");
    });
    $timeout = $loop->delay(1.0, static function () use ($loop): void {
        $loop->stop();
    });
    $exchange = new Http1ConnectedExchange(
        $loop,
        $connection,
        $request,
        static function (int $status) use (&$headers): void {
            $headers = $status;
        },
        static function (string $chunk) use (&$body): void {
            $body .= $chunk;
        },
        static function (?Connection $reusable, ?Throwable $error) use (&$completed, &$failure, $loop): void {
            $completed = $reusable;
            $failure = $error;
            $loop->stop();
        },
    );
    $releaseTimer = $loop->delay(0.01, static function () use ($source, $exchange, &$resumed): void {
        $resumed = true;
        $source->release();
        $exchange->resumeUpload();
    });

    try {
        $exchange->start();
        $loop->run();
        expect($resumed)->toBeTrue()
            ->and($receivedWire)->toContain("8\r\nfragment\r\n0\r\n\r\n")
            ->and($headers)->toBe(200)
            ->and($body)->toBe('ok')
            ->and($failure)->toBeNull()
            ->and($completed)->toBe($connection)
            ->and($connection->isPoolIdleSafe())->toBeTrue();
    } finally {
        $loop->cancel($releaseTimer);
        $loop->cancel($watcher);
        $loop->cancel($timeout);
        $exchange->close();
        $connection->abort();
        fclose($serverSocket);
    }

    expect($loop->diagnostics()->readWatchers)->toBe(0)
        ->and($loop->diagnostics()->writeWatchers)->toBe(0)
        ->and($loop->diagnostics()->timersActive)->toBe(0);
});
