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

if ($argc !== 5) {
    throw new InvalidArgumentException(
        'Usage: php http3_interop_server.php <port> <certificate> <private-key> <ready-file>',
    );
}

$port = (int) $argv[1];
$certificate = $argv[2];
$privateKey = $argv[3];
$readyFile = $argv[4];
$options = new Http3Options();
$listener = PhpQuicListener::bind(
    '127.0.0.1',
    $port,
    $options->listenerOptions(new TlsOptions($certificate, $privateKey)),
);
fwrite(STDERR, "interop: listener-bound\n");

$served = false;
$worker = new PhpQuicHttp3Worker(
    $listener,
    static function (HttpRequest $request, ResponseWriterInterface $writer) use (&$served): void {
        fwrite(STDERR, sprintf("interop: dispatched %s %s\n", $request->method, $request->target));
        $responses = [
            '/interop?client=aioquic' => 'runwire-aioquic-ok',
            '/interop?client=ngtcp2' => 'runwire-ngtcp2-ok',
        ];
        $body = $responses[$request->target] ?? null;
        if ($request->method !== 'GET' || $body === null) {
            $body = 'unexpected-request';
            $writer->start(400, Headers::fromArray([
                'content-type' => 'text/plain',
                'content-length' => (string) strlen($body),
            ]));
            $writer->end($body);
            $served = true;
            fwrite(STDERR, "interop: rejected-request\n");

            return;
        }

        $request->body->onEnd(static function () use (&$served, $writer, $body): void {
            fwrite(STDERR, "interop: request-ended\n");
            $writer->start(200, Headers::fromArray([
                'content-type' => 'text/plain',
                'content-length' => (string) strlen($body),
            ]));
            $writer->end($body);
            $served = true;
            fwrite(STDERR, "interop: response-ended\n");
        });
    },
    $options->limits,
    16,
);

if (file_put_contents($readyFile, 'ready', LOCK_EX) === false) {
    throw new RuntimeException('Unable to publish HTTP/3 interoperability readiness.');
}
fwrite(STDERR, "interop: ready\n");

$deadline = microtime(true) + 8.0;
while (!$served && microtime(true) < $deadline) {
    $worker->tick($options->pollTimeoutSeconds);
}
if (!$served) {
    throw new RuntimeException(sprintf(
        'Independent HTTP/3 client did not complete a request; worker connections=%d.',
        $worker->connectionCount(),
    ));
}

$worker->stopAccepting();
$drainDeadline = microtime(true) + 2.0;
while (!$worker->drainComplete() && microtime(true) < $drainDeadline) {
    $worker->tick($options->pollTimeoutSeconds);
}
fwrite(STDERR, sprintf("interop: complete drain=%s\n", $worker->drainComplete() ? 'yes' : 'no'));