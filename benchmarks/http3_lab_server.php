<?php

declare(strict_types=1);

use Infocyph\Runwire\Http\Headers;
use Infocyph\Runwire\Http\Http3\Http3Limits;
use Infocyph\Runwire\Http\Http3\Http3Options;
use Infocyph\Runwire\Http\Http3\Quic\PhpQuicHttp3Worker;
use Infocyph\Runwire\Http\Http3\Quic\PhpQuicListener;
use Infocyph\Runwire\Http\HttpRequest;
use Infocyph\Runwire\Http\ResponseWriterInterface;
use Infocyph\Runwire\Network\TlsOptions;

require dirname(__DIR__) . '/vendor/autoload.php';

if ($argc !== 12) {
    throw new InvalidArgumentException(
        'Usage: php http3_lab_server.php <port> <certificate> <private-key> <ready-file> <expected> <poll> <max-writes> <max-streams-per-pump> <max-concurrent> <payload> <max-reads>',
    );
}

$port = (int) $argv[1];
$certificate = $argv[2];
$privateKey = $argv[3];
$readyFile = $argv[4];
$expected = (int) $argv[5];
$pollTimeout = (float) $argv[6];
$maxWrites = (int) $argv[7];
$maxStreamsPerPump = (int) $argv[8];
$maxConcurrent = (int) $argv[9];
$payloadBytes = (int) $argv[10];
$maxReads = (int) $argv[11];
$maxRequestStreamsPerConnection = (int) (getenv('RUNWIRE_H3_MAX_STREAMS_PER_CONNECTION') ?: '10000');

$limits = new Http3Limits(
    maxConcurrentRequestStreams: $maxConcurrent,
    maxRequestStreamsPerConnection: $maxRequestStreamsPerConnection,
    maxResponseFramePayloadBytes: 16_384,
    maxWritesPerFlush: $maxWrites,
    maxStreamsAcceptedPerPump: $maxStreamsPerPump,
    maxReadsPerPump: $maxReads,
);
$options = new Http3Options($limits, pollTimeoutSeconds: $pollTimeout);
$listener = PhpQuicListener::bind(
    '127.0.0.1',
    $port,
    $options->listenerOptions(new TlsOptions($certificate, $privateKey)),
);

$body = str_repeat('x', $payloadBytes);
$served = 0;
$worker = new PhpQuicHttp3Worker(
    $listener,
    static function (HttpRequest $request, ResponseWriterInterface $writer) use (&$served, $body): void {
        $valid = $request->method === 'GET' && str_starts_with($request->target, '/benchmark?request=');
        $response = $valid ? $body : 'bad';
        $request->body->onEnd(static function () use (&$served, $writer, $response, $valid): void {
            $writer->start(
                $valid ? 200 : 400,
                Headers::fromArray([
                    'content-type' => 'application/octet-stream',
                    'content-length' => (string) strlen($response),
                ]),
            );
            $writer->end($response);
            ++$served;
        });
    },
    $limits,
    32,
);

if (file_put_contents($readyFile, 'ready', LOCK_EX) === false) {
    throw new RuntimeException('Unable to publish HTTP/3 playground readiness.');
}

$deadline = microtime(true) + 60.0;
while ($served < $expected && microtime(true) < $deadline) {
    $worker->tick($pollTimeout);
}

if ($served !== $expected) {
    throw new RuntimeException(sprintf('HTTP/3 playground served %d of %d requests.', $served, $expected));
}

if (getenv('RUNWIRE_H3_BENCH_PEER_CLOSE_COMPLETION') === '1') {
    $peerCloseDeadline = microtime(true) + 5.0;
    while ($worker->connectionCount() > 0 && microtime(true) < $peerCloseDeadline) {
        $worker->tick($pollTimeout);
    }
    if ($worker->connectionCount() > 0) {
        throw new RuntimeException('HTTP/3 sustained benchmark peer connection did not close.');
    }

    exit(0);
}

$worker->stopAccepting();
$drainDeadline = microtime(true) + 5.0;
while (!$worker->drainComplete() && microtime(true) < $drainDeadline) {
    $worker->tick($pollTimeout);
}

if (!$worker->drainComplete()) {
    throw new RuntimeException('HTTP/3 playground worker did not drain.');
}
