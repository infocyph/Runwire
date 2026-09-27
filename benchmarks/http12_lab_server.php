<?php

declare(strict_types=1);

use Infocyph\Runwire\Http\Headers;
use Infocyph\Runwire\Http\Http2\Http2Limits;
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
        'Usage: php http12_lab_server.php <port> <payload-bytes> <workers> <tcp-nodelay:0|1> <certificate|-> <private-key|->',
    );
}

$port = (int) $argv[1];
$payloadBytes = (int) $argv[2];
$workers = (int) $argv[3];
$tcpNoDelay = $argv[4] === '1';
$certificate = $argv[5];
$privateKey = $argv[6];

if ($port < 1 || $port > 65_535 || $payloadBytes < 0 || $payloadBytes > 1_048_576 || $workers < 1 || $workers > 16) {
    throw new InvalidArgumentException('Invalid HTTP/1+2 playground server arguments.');
}

$body = str_repeat('x', $payloadBytes);
$maxStreamsPerConnection = (int) (getenv('RUNWIRE_H2_MAX_STREAMS_PER_CONNECTION') ?: '10000');
if ($maxStreamsPerConnection < 1) {
    throw new InvalidArgumentException('HTTP/2 max streams per connection must be positive.');
}
$tls = $certificate === '-'
    ? null
    : new TlsOptions(
        $certificate,
        $privateKey === '-' ? null : $privateKey,
        alpnProtocols: ['h2', 'http/1.1'],
    );

$server = new Server(
    name: 'protocol-lab',
    address: '127.0.0.1:' . $port,
    handler: static function (HttpRequest $request, ResponseWriterInterface $writer) use ($body): void {
        $valid = $request->method === 'GET' && str_starts_with($request->target, '/benchmark');
        $response = $valid ? $body : 'bad';
        $writer->start(
            $valid ? 200 : 400,
            Headers::fromArray([
                'content-type' => 'application/octet-stream',
                'content-length' => (string) strlen($response),
            ]),
        );
        $writer->end($response);
    },
    workers: $workers,
    listener: new ListenerOptions(
        socketContext: $tcpNoDelay ? ['tcp_nodelay' => true] : [],
    ),
    tls: $tls,
    http2: new Http2Limits(
        maxStreamsPerConnection: $maxStreamsPerConnection,
    ),
);

Runtime::create(new RuntimeOptions(driver: RuntimeDriver::NATIVE))
    ->listen($server)
    ->run();
