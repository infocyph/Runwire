<?php

declare(strict_types=1);

use Infocyph\Runwire\Http\Http3\Http3Options;
use Infocyph\Runwire\Http\Http3\Quic\PhpQuicHttp3Worker;
use Infocyph\Runwire\Http\Http3\Quic\PhpQuicListener;
use Infocyph\Runwire\Http\HttpRequest;
use Infocyph\Runwire\Http\ResponseWriterInterface;
use Infocyph\Runwire\Network\TlsOptions;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

if ($argc !== 5) {
    fwrite(STDERR, "Usage: php http3_interop_server.php <port> <certificate> <private-key> <ready-file>\n");
    exit(64);
}

$port = (int) $argv[1];
$certificate = $argv[2];
$privateKey = $argv[3];
$readyFile = $argv[4];

try {
    $options = new Http3Options();
    $listener = PhpQuicListener::bind(
        '127.0.0.1',
        $port,
        $options->listenerOptions(new TlsOptions($certificate, $privateKey)),
    );
    $served = false;
    $worker = new PhpQuicHttp3Worker(
        $listener,
        static function (HttpRequest $request, ResponseWriterInterface $writer) use (&$served): void {
            if ($request->method !== 'GET' || $request->target !== '/interop?client=aioquic') {
                $writer->status(400)->end('unexpected-request');
                $served = true;

                return;
            }

            $request->body->onEnd(static function () use (&$served, $writer): void {
                $writer->header('content-type', 'text/plain');
                $writer->end('runwire-aioquic-ok');
                $served = true;
            });
        },
        $options->limits,
        16,
    );

    if (file_put_contents($readyFile, 'ready', LOCK_EX) === false) {
        throw new RuntimeException('Unable to publish HTTP/3 interoperability readiness.');
    }

    $deadline = microtime(true) + 8.0;
    while (!$served && microtime(true) < $deadline) {
        $worker->tick($options->pollTimeoutSeconds);
    }
    if (!$served) {
        throw new RuntimeException('Independent HTTP/3 client did not complete a request.');
    }

    $worker->stopAccepting();
    $drainDeadline = microtime(true) + 2.0;
    while (!$worker->drainComplete() && microtime(true) < $drainDeadline) {
        $worker->tick($options->pollTimeoutSeconds);
    }

    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, $error::class . ': ' . $error->getMessage() . PHP_EOL);
    exit(70);
}
