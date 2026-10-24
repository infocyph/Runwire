<?php

declare(strict_types=1);

use Infocyph\Runwire\Http\Headers;
use Infocyph\Runwire\Http\Http3\Http3Options;
use Infocyph\Runwire\Http\Http3\Quic\PhpQuicHttp3Worker;
use Infocyph\Runwire\Http\Http3\Quic\PhpQuicListener;
use Infocyph\Runwire\Http\HttpRequest;
use Infocyph\Runwire\Http\ResponseWriterInterface;
use Infocyph\Runwire\Network\TlsOptions;

require dirname(__DIR__) . '/vendor/autoload.php';

if ($argc !== 6) {
    throw new InvalidArgumentException(
        'Usage: php http3_server.php <port> <certificate> <private-key> <ready-file> <expected-requests>',
    );
}

$port = (int) $argv[1];
$certificate = $argv[2];
$privateKey = $argv[3];
$readyFile = $argv[4];
$expectedRequests = (int) $argv[5];
if ($expectedRequests < 1 || $expectedRequests > 5_000) {
    throw new InvalidArgumentException('Expected HTTP/3 benchmark request count must be between 1 and 5000.');
}

$options = new Http3Options();
$listener = PhpQuicListener::bind(
    '127.0.0.1',
    $port,
    $options->listenerOptions(new TlsOptions($certificate, $privateKey)),
);

$served = 0;
$worker = new PhpQuicHttp3Worker(
    $listener,
    static function (HttpRequest $request, ResponseWriterInterface $writer) use (&$served): void {
        $valid = $request->method === 'GET' && str_starts_with($request->target, '/benchmark?request=');
        $body = $valid ? 'ok' : 'bad';
        $status = $valid ? 200 : 400;

        $request->body->onEnd(static function () use (&$served, $writer, $body, $status): void {
            $writer->start($status, Headers::fromArray([
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
    throw new RuntimeException('Unable to publish HTTP/3 benchmark readiness.');
}

$deadline = microtime(true) + 30.0;
while ($served < $expectedRequests && microtime(true) < $deadline) {
    $worker->tick($options->pollTimeoutSeconds);
}
if ($served !== $expectedRequests) {
    throw new RuntimeException(sprintf(
        'HTTP/3 benchmark completed %d of %d expected requests.',
        $served,
        $expectedRequests,
    ));
}

$worker->stopAccepting();
$drainDeadline = microtime(true) + 3.0;
while (!$worker->drainComplete() && microtime(true) < $drainDeadline) {
    $worker->tick($options->pollTimeoutSeconds);
}
if (!$worker->drainComplete()) {
    throw new RuntimeException('HTTP/3 benchmark worker did not drain before its deadline.');
}
