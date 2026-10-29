<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Infocyph\Runwire\Executor\ProcessExecutor;
use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Network\QuicClientOptions;
use Infocyph\Runwire\Network\QuicHandshakeProbe;

if ($argc !== 4 || filter_var($argv[2], FILTER_VALIDATE_INT) === false) {
    throw new InvalidArgumentException('Expected host, UDP port and trusted CA file.');
}
$host = $argv[1];
$port = (int) $argv[2];
$trustedCa = $argv[3];
$loop = new SelectLoop();
$executor = new ProcessExecutor($loop, 1, 2);
$probe = new QuicHandshakeProbe($executor);
$results = [];

try {
    $probe->connect(
        $host,
        $port,
        new QuicClientOptions('localhost', caFile: $trustedCa, connectTimeoutMilliseconds: 5_000),
        static function ($value, ?Throwable $error) use (&$results, $executor): void {
            $results[] = [$value, $error];
            $executor->close();
        },
    );
    $loop->run();

    if (count($results) !== 1 || $results[0][1] !== null
        || ($results[0][0]['verified'] ?? null) !== true
        || ($results[0][0]['backend'] ?? null) !== 'ext-quic'
        || ($results[0][0]['alpn'] ?? null) !== 'h3'
        || $loop->diagnostics()->readWatchers !== 0
        || $loop->diagnostics()->writeWatchers !== 0
        || $loop->diagnostics()->timersActive !== 0
        || $executor->diagnostics()['workers'] !== 0) {
        throw new RuntimeException(
            'Off-loop QUIC handshake acceptance failed: ' .
            ($results[0][1] instanceof Throwable ? $results[0][1]->getMessage() : 'invalid result'),
        );
    }

    // The independent TLS endpoint only supports localhost and ALPN h3.
    // Neither a wrong hostname nor an unsupported ALPN may be accepted.
    foreach ([
        ['name' => 'mismatched.example.test', 'alpn' => ['h3']],
        ['name' => 'localhost', 'alpn' => ['h2']],
    ] as $negativeCase) {
        $negativeLoop = new SelectLoop();
        $negativeExecutor = new ProcessExecutor($negativeLoop, 1, 1);
        $negativeResult = [];
        try {
            (new QuicHandshakeProbe($negativeExecutor))->connect(
                $host,
                $port,
                new QuicClientOptions(
                    $negativeCase['name'],
                    alpnProtocols: $negativeCase['alpn'],
                    caFile: $trustedCa,
                    connectTimeoutMilliseconds: 3_000,
                ),
                static function (mixed $value, ?Throwable $error) use (&$negativeResult, $negativeExecutor): void {
                    $negativeResult[] = [$value, $error];
                    $negativeExecutor->close();
                },
            );
            $negativeLoop->run();
            if (count($negativeResult) !== 1 || $negativeResult[0][1] === null
                || $negativeResult[0][0] !== null
                || $negativeLoop->diagnostics()->readWatchers !== 0
                || $negativeLoop->diagnostics()->writeWatchers !== 0
                || $negativeLoop->diagnostics()->timersActive !== 0) {
                throw new RuntimeException('An unverified QUIC hostname or ALPN was not rejected cleanly.');
            }
        } finally {
            $negativeExecutor->close();
        }
    }

    fwrite(STDOUT, json_encode([
        'verified' => true,
        'untrusted_hostname_rejected' => true,
        'unsupported_alpn_rejected' => true,
        'backend' => 'ext-quic',
        'worker_isolation' => true,
    ], JSON_THROW_ON_ERROR) . PHP_EOL);
} finally {
    $executor->close();
}
