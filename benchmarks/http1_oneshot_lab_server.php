<?php

declare(strict_types=1);

use Infocyph\Runwire\Http\HttpRequest;
use Infocyph\Runwire\Http\ResponseWriterInterface;
use Infocyph\Runwire\Network\ListenerOptions;
use Infocyph\Runwire\Network\TlsOptions;
use Infocyph\Runwire\Runtime;
use Infocyph\Runwire\Runtime\Enum\RuntimeDriver;
use Infocyph\Runwire\RuntimeOptions;
use Infocyph\Runwire\Server;

$runtimeRoot = getenv('RUNWIRE_BENCH_RUNTIME_ROOT');
$runtimeRoot = is_string($runtimeRoot) && $runtimeRoot !== '' ? $runtimeRoot : dirname(__DIR__);
require rtrim($runtimeRoot, '/\\') . '/vendor/autoload.php';

if ($argc !== 7) {
    throw new InvalidArgumentException(
        'Usage: php http1_oneshot_lab_server.php <port> <payload-bytes> <workers> <tcp-nodelay:0|1> <certificate|-> <private-key|->',
    );
}

$port = (int) $argv[1];
$payloadBytes = (int) $argv[2];
$workers = (int) $argv[3];
$tcpNoDelay = $argv[4] === '1';
$certificate = $argv[5];
$privateKey = $argv[6];

if ($port < 1 || $port > 65_535 || $payloadBytes < 0 || $payloadBytes > 1_048_576 || $workers < 1 || $workers > 16) {
    throw new InvalidArgumentException('Invalid HTTP/1 one-shot validation server arguments.');
}

$body = str_repeat('x', $payloadBytes);
$tls = $certificate === '-'
    ? null
    : new TlsOptions(
        $certificate,
        $privateKey === '-' ? null : $privateKey,
        alpnProtocols: ['http/1.1'],
    );

$server = new Server(
    name: 'http1-one-shot-validation',
    address: '127.0.0.1:' . $port,
    handler: static function (HttpRequest $request, ResponseWriterInterface $writer) use ($body): void {
        if ($request->method !== 'GET' || !str_starts_with($request->target, '/benchmark')) {
            $writer->end('bad');

            return;
        }

        $writer->end($body);
    },
    workers: $workers,
    listener: new ListenerOptions(
        socketContext: $tcpNoDelay ? ['tcp_nodelay' => true] : [],
    ),
    tls: $tls,
);

Runtime::create(new RuntimeOptions(driver: RuntimeDriver::NATIVE))
    ->listen($server)
    ->run();
