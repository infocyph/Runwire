<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Network\QuicClientOptions;
use Infocyph\Runwire\Network\QuicClientSession;

if ($argc !== 4 || filter_var($argv[2], FILTER_VALIDATE_INT) === false) {
    throw new InvalidArgumentException('Expected host, UDP port and trusted CA file.');
}
$loop = new SelectLoop();
$session = new QuicClientSession($loop, 8);
$completed = [];
$failure = null;
$stop = static function (Throwable $error) use (&$failure, $session): void {
    $failure ??= $error;
    $session->dispose();
};

$exchange = static function (string $payload, callable $done) use ($session, $loop, $stop): void {
    $session->command('open_bidi', 0, '', false,
        static function (mixed $opened, ?Throwable $error) use ($payload, $session, $loop, $stop, $done): void {
            if ($error !== null || !is_array($opened) || !is_int($opened['stream'] ?? null)) {
                $stop($error ?? new RuntimeException('QUIC worker refused a bidirectional stream.'));
                return;
            }
            $stream = $opened['stream'];
            $session->command('write', $stream, $payload, true,
                static function (mixed $result, ?Throwable $error) use ($payload, $session, $loop, $stop, $done, $stream): void {
                    if ($error !== null || ($result['written'] ?? null) !== strlen($payload)) {
                        $stop($error ?? new RuntimeException('QUIC worker did not accept the full test upload.'));
                        return;
                    }
                    $response = '';
                    $read = null;
                    $read = static function () use (&$read, &$response, $session, $loop, $stop, $done, $stream, $payload): void {
                        $session->command('read', $stream, '', false,
                            static function (mixed $reply, ?Throwable $error) use (&$read, &$response, $session, $loop, $stop, $done, $payload): void {
                                if ($error !== null || !is_array($reply) || !is_string($reply['data'] ?? null)) {
                                    $stop($error ?? new RuntimeException('QUIC worker returned invalid response data.'));
                                    return;
                                }
                                $bytes = base64_decode($reply['data'], true);
                                if (!is_string($bytes)) {
                                    $stop(new RuntimeException('Native QUIC worker returned invalid base64.'));
                                    return;
                                }
                                $response .= $bytes;
                                if (strlen($response) >= strlen($payload)) {
                                    if ($response !== $payload) {
                                        $stop(new RuntimeException('Independent QUIC echo payload mismatch.'));
                                        return;
                                    }
                                    $done();
                                    return;
                                }
                                if (($reply['eof'] ?? null) === true) {
                                    $stop(new RuntimeException('QUIC reply terminated before full payload delivery.'));
                                    return;
                                }
                                $loop->delay(0.01, $read);
                            },
                        );
                    };
                    $read();
                },
            );
        },
    );
};

try {
    $timeout = $loop->delay(10.0, static function () use ($stop): void {
        $stop(new RuntimeException('Independent QUIC session interoperability timed out.'));
    });
    $session->connect(
        $argv[1],
        (int) $argv[2],
        new QuicClientOptions(
            'localhost',
            alpnProtocols: ['runwire-echo'],
            caFile: $argv[3],
            connectTimeoutMilliseconds: 4_000,
            maxOpenStreams: 8,
        ),
        static function (mixed $value, ?Throwable $error) use ($session, $loop, $stop, $exchange, &$completed, $timeout): void {
            if ($error !== null || ($value['alpn'] ?? null) !== 'runwire-echo' || !$session->active()) {
                $stop($error ?? new RuntimeException('QUIC session TLS/ALPN verification failed.'));
                return;
            }

            $exchange('first-quic-stream',
                static function () use ($exchange, $session, $loop, $stop, &$completed, $timeout): void {
                    $completed[] = 'first-quic-stream';
                    $exchange('second-quic-stream',
                        static function () use ($session, $loop, $stop, &$completed, $timeout): void {
                            $completed[] = 'second-quic-stream';
                            $session->command('close', 0, '', false,
                                static function (mixed $value, ?Throwable $error) use ($loop, $stop, $timeout): void {
                                    if ($error !== null || ($value['closed'] ?? null) !== true) {
                                        $stop($error ?? new RuntimeException('QUIC session failed to close cleanly.'));
                                        return;
                                    }
                                    $loop->cancel($timeout);
                                },
                            );
                        },
                    );
                },
            );
        },
    );
    $loop->run();

    if ($failure !== null) {
        throw $failure;
    }
    if ($completed !== ['first-quic-stream', 'second-quic-stream']
        || $session->active() || $loop->diagnostics()->readWatchers !== 0
        || $loop->diagnostics()->writeWatchers !== 0
        || $loop->diagnostics()->timersActive !== 0) {
        throw new RuntimeException('Native QUIC worker session leaked ownership or failed echo parity.');
    }

    fwrite(STDOUT, json_encode([
        'verified' => true,
        'alpn' => 'runwire-echo',
        'bidi_streams' => count($completed),
        'persistent_worker' => true,
    ], JSON_THROW_ON_ERROR) . PHP_EOL);
} finally {
    $session->dispose();
}
