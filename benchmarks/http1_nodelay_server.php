<?php

declare(strict_types=1);

use Infocyph\Runwire\Http\Headers;
use Infocyph\Runwire\Http\HttpRequest;
use Infocyph\Runwire\Http\ResponseWriterInterface;
use Infocyph\Runwire\Network\ListenerOptions;
use Infocyph\Runwire\Runtime;
use Infocyph\Runwire\Runtime\Enum\RuntimeDriver;
use Infocyph\Runwire\RuntimeOptions;
use Infocyph\Runwire\Server;

require dirname(__DIR__) . '/vendor/autoload.php';

if ($argc !== 2) {
    throw new InvalidArgumentException('Usage: php http1_nodelay_server.php <port>');
}

$port = (int) $argv[1];
if ($port < 1 || $port > 65_535) {
    throw new InvalidArgumentException('Benchmark port must be between 1 and 65535.');
}

$tcpNoDelay = getenv('RUNWIRE_SERVER_TCP_NODELAY') === '1';
$server = new Server(
    name: 'benchmark',
    address: '127.0.0.1:' . $port,
    handler: static function (HttpRequest $request, ResponseWriterInterface $writer): void {
        $valid = $request->method === 'GET' && str_starts_with($request->target, '/benchmark');
        $body = $valid ? 'ok' : 'bad';
        $writer->start(
            $valid ? 200 : 400,
            Headers::fromArray([
                'content-type' => 'text/plain',
                'content-length' => (string) strlen($body),
            ]),
        );
        $writer->end($body);
    },
    workers: 1,
    listener: new ListenerOptions(
        socketContext: $tcpNoDelay ? ['tcp_nodelay' => true] : [],
    ),
);

Runtime::create(new RuntimeOptions(driver: RuntimeDriver::NATIVE))
    ->listen($server)
    ->run();
