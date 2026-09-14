<?php

declare(strict_types=1);

use Infocyph\Runwire\Http\Headers;
use Infocyph\Runwire\Http\Http3\Http3Options;
use Infocyph\Runwire\Http\Http3\Quic\PhpQuicHttp3Worker;
use Infocyph\Runwire\Http\Http3\Quic\PhpQuicListener;
use Infocyph\Runwire\Http\HttpRequest;
use Infocyph\Runwire\Http\ResponseWriterInterface;
use Infocyph\Runwire\Network\TlsOptions;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

if ($argc !== 5 && $argc !== 6) {
    throw new InvalidArgumentException(
        'Usage: php http3_interop_server.php <port> <certificate> <private-key> <ready-file> [expected-requests]',
    );
}

$port = (int) $argv[1];
$certificate = $argv[2];
$privateKey = $argv[3];
$readyFile = $argv[4];
$expectedRequests = isset($argv[5]) ? (int) $argv[5] : 1;
if ($expectedRequests < 1 || $expectedRequests > 2_000) {
    throw new InvalidArgumentException('Expected HTTP/3 request count must be between 1 and 2000.');
}

$options = new Http3Options();
$listener = PhpQuicListener::bind(
    '127.0.0.1',
    $port,
    $options->listenerOptions(new TlsOptions($certificate, $privateKey)),
);
fwrite(STDERR, "interop: listener-bound\n");

$served = 0;
$worker = new PhpQuicHttp3Worker(
    $listener,
    static function (HttpRequest $request, ResponseWriterInterface $writer) use (&$served): void {
        fwrite(STDERR, sprintf("interop: dispatched %s %s\n", $request->method, $request->target));
        $responses = [
            '/interop?client=aioquic' => 'runwire-aioquic-ok',
            '/interop-ngtcp2' => 'runwire-ngtcp2-ok',
        ];
        $body = $responses[$request->target] ?? null;
        if ($body === null && str_starts_with($request->target, '/soak?')) {
            $body = 'runwire-soak-ok';
        }
        if ($request->method !== 'GET' || $body === null) {
            $body = 'unexpected-request';
            $writer->start(400, Headers::fromArray([
                'content-type' => 'text/plain',
                'content-length' => (string) strlen($body),
            ]));
            $writer->end($body);
            ++$served;
            fwrite(STDERR, "interop: rejected-request\n");

            return;
        }

        $request->body->onEnd(static function () use (&$served, $writer, $body): void {
            $writer->start(200, Headers::fromArray([
                'content-type' => 'text/plain',
                'content-length' => (string) strlen($body),
            ]));
            $writer->end($body);
            ++$served;
        });
    },
    $options->limits,
    16,
);

if (file_put_contents($readyFile, 'ready', LOCK_EX) === false) {
    throw new RuntimeException('Unable to publish HTTP/3 interoperability readiness.');
}
fwrite(STDERR, "interop: ready\n");

$deadline = microtime(true) + 20.0;
while ($served < $expectedRequests && microtime(true) < $deadline) {
    $worker->tick($options->pollTimeoutSeconds);
}
if ($served !== $expectedRequests) {
    throw new RuntimeException(sprintf(
        'Independent HTTP/3 client completed %d of %d requests; worker connections=%d.',
        $served,
        $expectedRequests,
        $worker->connectionCount(),
    ));
}

$worker->stopAccepting();
$drainDeadline = microtime(true) + 3.0;
while (!$worker->drainComplete() && microtime(true) < $drainDeadline) {
    $worker->tick($options->pollTimeoutSeconds);
}
if (!$worker->drainComplete()) {
    throw new RuntimeException('HTTP/3 interoperability worker did not drain before its deadline.');
}
fwrite(STDERR, sprintf("interop: complete served=%d drain=yes\n", $served));
