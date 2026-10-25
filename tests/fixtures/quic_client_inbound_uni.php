<?php

declare(strict_types=1);

use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Network\QuicClientOptions;
use Infocyph\Runwire\Network\QuicClientSession;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

if ($argc !== 4 || filter_var($argv[2], FILTER_VALIDATE_INT) === false) {
    throw new InvalidArgumentException('Expected host, UDP port, and trusted certificate.');
}

$loop = new SelectLoop();
$session = new QuicClientSession($loop, 4);
$complete = false;
$failure = null;
$payload = '';

$stop = static function (Throwable $error) use (&$failure, $session): void {
    $failure ??= $error;
    $session->dispose();
};
$timeout = $loop->delay(8.0, static function () use ($stop): void {
    $stop(new RuntimeException('Independent inbound native QUIC stream timed out.'));
});

try {
    $read = null;
    $read = static function (int $stream) use (&$read, $session, $loop, $stop, $timeout, &$payload, &$complete): void {
        $session->command('read', $stream, '', false,
            static function (mixed $response, ?Throwable $error) use (&$read, $session, $loop, $stop, $timeout, $stream, &$payload, &$complete): void {
                if ($error !== null || !is_array($response) || !is_string($response['data'] ?? null)) {
                    $stop($error ?? new RuntimeException('Invalid peer QUIC worker read.'));

                    return;
                }
                $data = base64_decode($response['data'], true);
                if (!is_string($data) || strlen($payload) + strlen($data) > 512) {
                    $stop(new RuntimeException('Malformed or unbounded peer QUIC payload.'));

                    return;
                }
                $payload .= $data;
                if (($response['eof'] ?? null) !== true) {
                    $loop->delay(0.01, static fn() => $read($stream));

                    return;
                }
                if ($payload !== 'peer-control-settings') {
                    $stop(new RuntimeException('Peer-created native QUIC stream payload mismatch.'));

                    return;
                }
                $session->command('close', 0, '', false,
                    static function (mixed $response, ?Throwable $error) use ($stop, $loop, $timeout, &$complete): void {
                        if ($error !== null || ($response['closed'] ?? null) !== true) {
                            $stop($error ?? new RuntimeException('QUIC close after peer EOF failed.'));

                            return;
                        }
                        $complete = true;
                        $loop->cancel($timeout);
                    },
                );
            },
        );
    };

    $accept = null;
    $accept = static function () use (&$accept, $session, $loop, $stop, $read): void {
        $session->command('accept', 0, '', false,
            static function (mixed $response, ?Throwable $error) use (&$accept, $session, $loop, $stop, $read): void {
                if ($error !== null || !is_array($response)) {
                    $stop($error ?? new RuntimeException('Native worker failed to accept peer stream.'));

                    return;
                }
                if (($response['pending'] ?? null) === true) {
                    $loop->delay(0.01, $accept);

                    return;
                }
                if (!is_int($response['stream'] ?? null) || ($response['incoming'] ?? null) !== true
                    || ($response['bidirectional'] ?? null) !== false) {
                    $stop(new RuntimeException('Peer-created native stream must be receive-only.'));

                    return;
                }
                $read($response['stream']);
            },
        );
    };

    $session->connect(
        $argv[1],
        (int) $argv[2],
        new QuicClientOptions('localhost', alpnProtocols: ['runwire-echo'], caFile: $argv[3],
            connectTimeoutMilliseconds: 4_000, maxOpenStreams: 4),
        static function (mixed $connection, ?Throwable $error) use ($session, $stop, $accept): void {
            if ($error !== null || ($connection['verified'] ?? null) !== true
                || ($connection['alpn'] ?? null) !== 'runwire-echo' || !$session->active()) {
                $stop($error ?? new RuntimeException('Independent QUIC TLS/ALPN verification failed.'));

                return;
            }
            $accept();
        },
    );

    $loop->run();
    if ($failure !== null) {
        throw $failure;
    }
    if (!$complete || $payload !== 'peer-control-settings' || $session->active()
        || $loop->diagnostics()->readWatchers !== 0
        || $loop->diagnostics()->writeWatchers !== 0
        || $loop->diagnostics()->timersActive !== 0) {
        throw new RuntimeException('Peer QUIC stream delivery or resource cleanup was incomplete.');
    }

    echo json_encode([
        'verified' => true,
        'independent_aioquic_peer' => true,
        'peer_initiated_unidirectional' => true,
        'payload_exact' => true,
        'peer_fin_observed' => true,
        'worker_owned' => true,
        'callback_cleanup' => true,
    ], JSON_THROW_ON_ERROR), PHP_EOL;
} finally {
    $session->dispose();
}
